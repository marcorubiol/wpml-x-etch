<?php
/**
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Admin;

use WP_Error;
use WpmlXEtch\Utils\Logger;

/**
 * Manages WPML translation jobs and ATE editor integration.
 */
class TranslationJobManager {

	/**
	 * Resolve the ATE editor URL for a post and target language.
	 *
	 * @param int      $post_id        The post ID to translate.
	 * @param string   $target_lang    The target language code.
	 * @param int|null $return_post_id Optional. The post ID to return to after translation. Defaults to $post_id.
	 * @return array{url: string, job_id: int}|WP_Error
	 */
	public function resolve_translate_url( int $post_id, string $target_lang, ?int $return_post_id = null ): array|WP_Error {
		global $wpdb;

		$translator_id = get_current_user_id();
		if ( ! $translator_id ) {
			return new WP_Error( 'no_user', 'A logged-in user is required to create translation jobs.', array( 'status' => 401 ) );
		}

		if ( null === $return_post_id ) {
			$return_post_id = $post_id;
		}

		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return new WP_Error( 'invalid_post', 'Invalid post' );
		}

		$trid = (int) apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
		if ( ! $trid ) {
			Logger::warning( 'No WPML translation group found', array(
				'post_id'   => $post_id,
				'post_type' => $post_type,
			) );
			return new WP_Error( 'no_trid', 'No WPML translation group' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$translation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT translation_id FROM {$wpdb->prefix}icl_translations
				 WHERE trid = %d AND language_code = %s",
				$trid,
				$target_lang
			)
		);

		if ( ! $translation_id ) {
			$translation_id = $this->synthesize_translation_record( $post_id, $post_type, $trid, $target_lang );
		}

		if ( ! $translation_id ) {
			return new WP_Error( 'no_translation', 'Could not synthesize translation record' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$status_row_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT rid, needs_update FROM {$wpdb->prefix}icl_translation_status WHERE translation_id = %d",
				$translation_id
			)
		);
		$status_rid   = $status_row_data ? (int) $status_row_data->rid : 0;
		$needs_update = ! $status_row_data || (int) $status_row_data->needs_update === 1;

		if ( ! $status_rid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'icl_translation_status',
				array(
					'translation_id'      => $translation_id,
					'status'              => TranslationDataQuery::ICL_TM_WAITING,
					'needs_update'        => 1,
					'translation_service' => 'local',
				),
				array( '%d', '%d', '%d', '%s' )
			);
			$status_rid = (int) $wpdb->insert_id;
		}

		$job_result = $this->ensure_job_exists( $post_id, $trid, $target_lang, $status_rid, $needs_update );
		if ( is_wp_error( $job_result ) ) {
			return $job_result;
		}
		$job_id = $job_result;

		// Mark as in-progress and assign to current user.
		// Keep needs_update intact — clearing it here would hide the "content
		// changed" signal if the user navigates back without completing the
		// translation.  WPML clears needs_update when the job is completed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->prefix . 'icl_translation_status',
			array(
				'status'        => TranslationDataQuery::ICL_TM_IN_PROGRESS,
				'translator_id' => $translator_id,
			),
			array( 'translation_id' => $translation_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);

		$this->force_ate_sync();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->prefix . 'icl_translate_job',
			array(
				'editor'        => 'ate',
				'translator_id' => $translator_id,
			),
			array( 'job_id' => $job_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		$original_post_id = (int) apply_filters(
			'wpml_object_id',
			$return_post_id,
			get_post_type( $return_post_id ),
			true,
			apply_filters( 'wpml_default_language', null )
		);

		$return_url = add_query_arg(
			array(
				'etch'          => BuilderPanel::ETCH_MAGIC_PARAM,
				'post_id'       => $original_post_id,
				'zs_wxe_return' => 1,
				'zs_wxe_lang'   => $target_lang,
			),
			trailingslashit( (string) get_option( 'home' ) )
		);

		$ate_url = apply_filters( 'wpml_tm_ate_jobs_editor_url', '', $job_id, $return_url );

		if ( $ate_url && ! is_wp_error( $ate_url ) ) {
			return array( 'url' => $ate_url, 'job_id' => $job_id );
		}

		// If the job was just created/updated, ATE may still be processing.
		// Signal the client to retry instead of falling back to the classic editor.
		if ( $needs_update ) {
			Logger::info( 'ATE URL not ready yet, signalling client to retry', array(
				'post_id' => $post_id,
				'job_id'  => $job_id,
			) );
			return array( 'status' => 'pending', 'job_id' => $job_id );
		}

		// Fallback to classic translation editor if ATE is not available
		// (only for jobs that were NOT just created — i.e. a persistent ATE issue).
		$classic_url = admin_url( 'admin.php?page=wpml-translation-management/menu/translations/translate-job.php&job_id=' . $job_id );

		Logger::warning( 'ATE URL not available, using classic editor', array(
			'post_id'     => $post_id,
			'job_id'      => $job_id,
			'classic_url' => $classic_url,
		) );

		return array( 'url' => $classic_url, 'job_id' => $job_id );
	}

	/**
	 * Refresh the translation job for a post+language so ATE sees fresh strings.
	 *
	 * Called after AI translation writes to icl_string_translations — the job
	 * fields in icl_translate must be regenerated so ATE shows the current state.
	 */
	public function refresh_job_for_post( int $post_id, string $target_lang, bool $complete = false, array $translations = array() ): void {
		global $wpdb;

		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return;
		}

		$trid = (int) apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
		if ( ! $trid ) {
			return;
		}

		$translation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT translation_id FROM {$wpdb->prefix}icl_translations
				 WHERE trid = %d AND language_code = %s",
				$trid,
				$target_lang
			)
		);

		if ( ! $translation_id ) {
			return;
		}

		$status_rid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rid FROM {$wpdb->prefix}icl_translation_status WHERE translation_id = %d",
				$translation_id
			)
		);

		if ( ! $status_rid ) {
			return;
		}

		$job_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT job_id FROM {$wpdb->prefix}icl_translate_job
				 WHERE rid = %d ORDER BY job_id DESC LIMIT 1",
				$status_rid
			)
		);

		if ( $job_id ) {
			$new_job_id = $this->refresh_translation_job( $post_id, $job_id );
		} else {
			$new_job_id = $this->create_translation_job( $post_id, $status_rid, 0 );
		}

		if ( ! $new_job_id ) {
			return;
		}

		if ( ! $complete ) {
			// Normal resync: WPML's carry-over in add_translation_job()
			// copies translations from the previous job revision.
			return;
		}

		// AI translation path: complete the job via WPML's native save
		// mechanism. This uses FieldCompression for correct encoding, fires
		// wpml_pro_translation_completed, and sets the proper job status.
		// When the user later opens ATE, the carry-over will work correctly
		// because the job was completed through WPML's internal state machine.
		$this->complete_job_via_wpml( $new_job_id, $target_lang, $translations );
	}

	/**
	 * Complete a translation job via WPML's native wpml_tm_save_data().
	 *
	 * This is the same path that ATE uses when a translator clicks "Complete".
	 * It handles FieldCompression, fires wpml_pro_translation_completed, and
	 * updates icl_translation_status — all through WPML's internal state machine.
	 */
	private function complete_job_via_wpml( int $job_id, string $lang, array $translations = array() ): void {
		if ( ! function_exists( 'wpml_tm_save_data' ) ) {
			if ( defined( 'WPML_TM_PATH' ) ) {
				require_once WPML_TM_PATH . '/inc/wpml-private-actions.php';
			}
			if ( ! function_exists( 'wpml_tm_save_data' ) ) {
				Logger::error( 'wpml_tm_save_data not available' );
				return;
			}
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$fields = $wpdb->get_results( $wpdb->prepare(
			"SELECT tid, field_type, field_data FROM {$wpdb->prefix}icl_translate
			 WHERE job_id = %d",
			$job_id
		) );

		if ( ! $fields ) {
			return;
		}

		$data = array(
			'job_id'   => $job_id,
			'complete' => 1,
			'fields'   => array(),
		);

		foreach ( $fields as $field ) {
			$translated_text = null;

			if ( str_starts_with( $field->field_type, 'package-string-' ) ) {
				$parts     = explode( '-', $field->field_type );
				$string_id = (int) end( $parts );

				if ( $string_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$translated_text = $wpdb->get_var( $wpdb->prepare(
						"SELECT value FROM {$wpdb->prefix}icl_string_translations
						 WHERE string_id = %d AND language = %s AND status = 10",
						$string_id,
						$lang
					) );
				}
			}

			if ( null === $translated_text || '' === $translated_text ) {
				// Non-package field or no translation: check the AI translations
				// map (keyed by original text), then fall back to original.
				$decoded      = base64_decode( $field->field_data );
				$decompressed = @gzuncompress( $decoded );
				$original_text = false !== $decompressed ? $decompressed : $decoded;

				if ( ! empty( $translations ) && isset( $translations[ $original_text ] ) ) {
					$translated_text = $translations[ $original_text ];
				} else {
					$translated_text = $original_text;
				}
			}

			$field_key                   = "field-{$field->tid}-0";
			$data['fields'][ $field_key ] = array(
				'tid'      => (int) $field->tid,
				'data'     => $translated_text,
				'finished' => 1,
				'format'   => 'base64',
			);
		}

		wpml_tm_save_data( $data, false );

		// NOTE: AI-translated strings appear as "Flagged for later" in ATE.
		// This is because ATE is a remote SaaS with its own review state.
		// Jobs::setReviewStatus() only updates the local DB — ATE's remote
		// copy retains NEEDS_REVIEW regardless. We accept this: the
		// translations work on the frontend, the flag is cosmetic within
		// WPML's editor. See ARCHITECTURE.md § "ATE review status" for
		// the full investigation.
	}

	/**
	 * Ensure a translation job exists and is ready for editing.
	 *
	 * Looks up, reopens, refreshes, or creates a job as needed.
	 *
	 * @return int|WP_Error Job ID on success, WP_Error on failure.
	 */
	private function ensure_job_exists( int $post_id, int $trid, string $target_lang, int $status_rid, bool $needs_update ): int|WP_Error {
		global $wpdb;

		$job_id = (int) apply_filters( 'wpml_translation_job_id', null, array(
			'trid'          => $trid,
			'language_code' => $target_lang,
		) );

		if ( ! $job_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$job_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT job_id FROM {$wpdb->prefix}icl_translate_job
					 WHERE rid = %d ORDER BY job_id DESC LIMIT 1",
					$status_rid
				)
			);
		}

		if ( $job_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$job_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT translated, editor_job_id FROM {$wpdb->prefix}icl_translate_job WHERE job_id = %d",
					$job_id
				)
			);
			$job_translated  = (int) ( $job_row->translated ?? 0 );
			$has_ate_job     = ! empty( $job_row->editor_job_id );

			if ( 1 === $job_translated ) {
				// Reopen the completed job for editing.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					$wpdb->prefix . 'icl_translate_job',
					array( 'translated' => 0 ),
					array( 'job_id' => $job_id ),
					array( '%d' ),
					array( '%d' )
				);

				Logger::info( 'Reopened completed translation job', array(
					'job_id'  => $job_id,
					'post_id' => $post_id,
				) );
			}

			$this->ensure_ate_editor( $job_id );

			if ( $needs_update || $this->job_missing_package_strings( $job_id, $post_id ) ) {
				// Content changed since last job, or job lacks package-string-*
				// fields — create a new revision via WPML's native path.
				$job_id = $this->refresh_translation_job( $post_id, $job_id );
				$has_ate_job = false; // New job needs ATE counterpart.
			}

			// Ensure ATE has a counterpart for this job (may be missing if
			// the job was created before ATE was available, or if ATE sync
			// never completed for it).
			if ( ! $has_ate_job ) {
				$this->ensure_ate_job( $job_id );
			}

			return $job_id;
		}

		// No job exists at all — create the first one.
		$job_id = $this->create_translation_job( $post_id, $status_rid, $job_id );

		if ( ! $job_id ) {
			Logger::error( 'Failed to create translation job', array(
				'post_id'    => $post_id,
				'status_rid' => $status_rid,
			) );
			return new WP_Error( 'no_job', 'WPML has not generated a translation job yet. Please try saving your page.' );
		}

		Logger::info( 'Created translation job', array(
			'post_id'     => $post_id,
			'target_lang' => $target_lang,
			'job_id'      => $job_id,
		) );

		return $job_id;
	}

	public function force_ate_sync(): void {
		if (
			! class_exists( '\WPML\TM\ATE\Sync\Process' ) ||
			! class_exists( '\WPML\TM\ATE\Download\Process' )
		) {
			return;
		}

		try {
			$args = new \WPML\TM\ATE\Sync\Arguments();
			$args->includeManualAndLongstandingJobs = true;
			$sync_result = \WPML\Container\make( \WPML\TM\ATE\Sync\Process::class )->run( $args );

			if ( ! empty( $sync_result->jobs ) ) {
				$jobs_data_array = json_decode( wp_json_encode( $sync_result->jobs ), true );
				\WPML\Container\make( \WPML\TM\ATE\Download\Process::class )->run( $jobs_data_array );
			}
		} catch ( \Throwable $e ) {
			Logger::warning( 'ATE sync failed', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Inserts directly into icl_translations instead of using
	 * wpml_set_element_language_details, which can trigger WPML to
	 * auto-create duplicate posts with their own trid (orphan originals).
	 */
	private function synthesize_translation_record( int $post_id, string $post_type, int $trid, string $target_lang ): int {
		global $wpdb;

		$element_type = 'post_' . $post_type;

		$source_lang = apply_filters( 'wpml_element_language_code', null, array(
			'element_id'   => $post_id,
			'element_type' => $element_type,
		) );

		// Check if a record already exists for this trid + language.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT translation_id FROM {$wpdb->prefix}icl_translations
				 WHERE trid = %d AND language_code = %s",
				$trid,
				$target_lang
			)
		);

		if ( $existing ) {
			return $existing;
		}

		// Insert a translation record directly — no element_id, no post creation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'icl_translations',
			array(
				'element_type'         => $element_type,
				'element_id'           => null,
				'trid'                 => $trid,
				'language_code'        => $target_lang,
				'source_language_code' => $source_lang,
			),
			array( '%s', null, '%d', '%s', '%s' )
		);

		$translation_id = (int) $wpdb->insert_id;

		Logger::info( 'Synthesized translation record (direct insert)', array(
			'post_id'        => $post_id,
			'post_type'      => $post_type,
			'trid'           => $trid,
			'target_lang'    => $target_lang,
			'source_lang'    => $source_lang,
			'translation_id' => $translation_id,
		) );

		return $translation_id;
	}

	/**
	 * Check if a job is missing package-string-* fields but the post has Etch strings.
	 */
	private function job_missing_package_strings( int $job_id, int $post_id ): bool {
		global $wpdb;

		$has_fields = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}icl_translate
			 WHERE job_id = %d AND field_type LIKE 'package-string-%%' LIMIT 1",
			$job_id
		) );

		if ( $has_fields ) {
			return false;
		}

		$has_strings = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}icl_strings s
			 JOIN {$wpdb->prefix}icl_string_packages p ON s.string_package_id = p.ID
			 WHERE p.kind = %s AND p.post_id = %d LIMIT 1",
			\WpmlXEtch\WPML\StringHandler::PACKAGE_KIND,
			$post_id
		) );

		return $has_strings;
	}

	private function ensure_ate_editor( int $job_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$job_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT editor, editor_job_id FROM {$wpdb->prefix}icl_translate_job WHERE job_id = %d",
				$job_id
			)
		);

		if ( ( $job_row->editor ?? null ) !== 'ate' ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$wpdb->prefix . 'icl_translate_job',
				array( 'editor' => 'ate' ),
				array( 'job_id' => $job_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Forces WPML to rescan Gutenberg content and creates a fresh translation
	 * package so the job contains the latest post content.
	 */
	private function create_translation_job( int $post_id, int $status_rid, int $job_id ): int {
		global $iclTranslationManagement;

		if ( ! isset( $iclTranslationManagement ) ) {
			return $job_id;
		}

		$post_obj = get_post( $post_id );

		// Register Etch strings before creating the translation package.
		$package_data = array(
			'kind'    => \WpmlXEtch\WPML\StringHandler::PACKAGE_KIND,
			'name'    => $post_id,
			'title'   => 'Etch Page ' . $post_id,
			'post_id' => $post_id,
		);
		do_action( 'wpml_page_builder_register_strings', $post_obj, $package_data );

		// Clear WPML cache to force it to see the freshest database content.
		wp_cache_delete( $post_id, 'wpml_tm' );
		wp_cache_delete( 'package_' . $post_id, 'wpml_tm' );

		$package    = $iclTranslationManagement->create_translation_package( $post_obj );
		$translator = get_current_user_id() ?: 1; // Fallback for TM API only; REST auth ensures a user exists.
		$job_id = (int) $iclTranslationManagement->add_translation_job( $status_rid, $translator, $package );

		// ATE notification is NOT fired here — it is handled by the caller
		// (ensure_job_exists via ensure_ate_job, or skipped for AI translations).

		return $job_id;
	}

	/**
	 * Refresh a translation job by creating a new revision via WPML's native path.
	 *
	 * Instead of manually manipulating icl_translate rows, this delegates to
	 * add_translation_job() which handles field compression, previous translation
	 * carry-over, field_wrap_tag, and taxonomy term inclusion.
	 *
	 * @return int The new job_id (or the old one if refresh failed).
	 */
	private function refresh_translation_job( int $post_id, int $job_id ): int {
		global $iclTranslationManagement, $wpdb;

		if ( ! isset( $iclTranslationManagement ) ) {
			return $job_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rid FROM {$wpdb->prefix}icl_translate_job WHERE job_id = %d",
				$job_id
			)
		);

		if ( ! $rid ) {
			Logger::warning( 'Cannot refresh job: no rid found', array(
				'job_id'  => $job_id,
				'post_id' => $post_id,
			) );
			return $job_id;
		}

		$post_obj = get_post( $post_id );

		// Force WPML Gutenberg to rescan strings.
		$package_data = array(
			'kind'    => \WpmlXEtch\WPML\StringHandler::PACKAGE_KIND,
			'name'    => $post_id,
			'title'   => 'Etch Page ' . $post_id,
			'post_id' => $post_id,
		);
		do_action( 'wpml_page_builder_register_strings', $post_obj, $package_data );

		wp_cache_delete( $post_id, 'wpml_tm' );
		wp_cache_delete( 'package_' . $post_id, 'wpml_tm' );

		$package    = $iclTranslationManagement->create_translation_package( $post_obj );
		$translator = get_current_user_id() ?: 1;

		// Create a new revision of the job via WPML's native path.
		// This handles: revision increment on the old job, previous translation
		// carry-over, field compression, field_wrap_tag, taxonomy terms.
		$new_job_id = (int) $iclTranslationManagement->add_translation_job( $rid, $translator, $package );

		if ( ! $new_job_id ) {
			Logger::error( 'Failed to create refreshed translation job', array(
				'post_id' => $post_id,
				'rid'     => $rid,
				'old_job' => $job_id,
			) );
			return $job_id;
		}

		Logger::info( 'Created new job revision (refresh)', array(
			'post_id'    => $post_id,
			'old_job_id' => $job_id,
			'new_job_id' => $new_job_id,
		) );

		// ATE notification is NOT fired here — it is handled by the caller
		// (ensure_job_exists via ensure_ate_job, or skipped for AI translations).

		return $new_job_id;
	}

	/** Create an ATE counterpart via CloneJobs API if one doesn't exist yet. */
	private function ensure_ate_job( int $job_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$job_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT editor_job_id FROM {$wpdb->prefix}icl_translate_job WHERE job_id = %d",
				$job_id
			)
		);

		if ( $job_row && ! empty( $job_row->editor_job_id ) ) {
			return;
		}

		try {
			if (
				! function_exists( 'wpml_tm_ams_ate_factories' ) ||
				! function_exists( 'wpml_tm_get_ate_job_records' ) ||
				! class_exists( 'WPML\TM\Menu\TranslationQueue\CloneJobs' )
			) {
				return;
			}

			$ate_api    = wpml_tm_ams_ate_factories()->get_ate_api();
			$ate_jobs   = new \WPML_TM_ATE_Jobs( wpml_tm_get_ate_job_records() );
			$clone_jobs = new \WPML\TM\Menu\TranslationQueue\CloneJobs( $ate_jobs, $ate_api );

			$clone_jobs->cloneWPMLJob( $job_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT editor_job_id FROM {$wpdb->prefix}icl_translate_job WHERE job_id = %d",
					$job_id
				)
			);

			// NOTE: ATE string sync was removed intentionally. The previous
			// usleep()-based timing hack was unreliable. If ATE opens before
			// strings are ready (empty editor), investigate ATE's API for a
			// status/ready endpoint to implement proper polling instead.
		} catch ( \Throwable $e ) {
			Logger::warning( 'ATE job creation failed', array( 'error' => $e->getMessage() ) );
		}
	}

}
