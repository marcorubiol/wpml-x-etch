<?php
/**
 * Resync translations handler.
 *
 * Recalculates translation state based on actual string content:
 * re-registers strings, cleans stale, copies meta, applies translations,
 * and auto-completes translations where all strings are already translated.
 *
 * Two scopes:
 * - Local (`resync`): a single post and its directly-referenced components.
 *   Invoked after every Etch UI save via the JS-side `resyncLocal()`
 *   helper that POSTs to the `/resync` REST endpoint — NOT via a direct
 *   `save_post` PHP hook. (The `save_post` path is handled separately by
 *   `MetaSync::process_post`, which queues referenced components on every
 *   page save to catch out-of-band content mutations.)
 * - Global (`resync_all`): every Etch original on the site. Used by the
 *   manual "Force Sync" button to bring the whole site back into alignment
 *   with WPML.
 *
 * Last-run state is persisted in option `zs_wxe_last_resync` so the panel
 * can surface a status indicator without needing live polling.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Admin;

use WP_Error;
use WpmlXEtch\Etch\ComponentParser;
use WpmlXEtch\WPML\StringHandler;
use WpmlXEtch\WPML\TranslationSync;
use WpmlXEtch\WPML\ContentTranslationHandler;
use WpmlXEtch\Utils\Logger;

class ResyncHandler {

	private const LAST_RUN_OPTION = 'zs_wxe_last_resync';

	private readonly ComponentParser $parser;
	private readonly StringHandler $string_handler;
	private readonly TranslationSync $translation_sync;
	private readonly ContentTranslationHandler $content_handler;

	public function __construct(
		ComponentParser $parser,
		StringHandler $string_handler,
		TranslationSync $translation_sync,
		ContentTranslationHandler $content_handler
	) {
		$this->parser           = $parser;
		$this->string_handler   = $string_handler;
		$this->translation_sync = $translation_sync;
		$this->content_handler  = $content_handler;
	}

	/**
	 * Resync translations for a post.
	 *
	 * When `$process_components` is true (default) the post's directly
	 * referenced components are processed in the same pass. The global
	 * resync sets it to false because every Etch post — components
	 * included — is iterated independently.
	 *
	 * @return array{success: bool, stats: array}|WP_Error
	 */
	public function resync( int $post_id, bool $process_components = true ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
		}

		$post_type = $post->post_type;
		$trid      = (int) apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
		if ( ! $trid ) {
			return new WP_Error( 'no_trid', 'Post has no translation group', array( 'status' => 400 ) );
		}

		// Verify this is the original post.
		$lang_details = apply_filters( 'wpml_element_language_details', null, array(
			'element_id'   => $post_id,
			'element_type' => 'post_' . $post_type,
		) );
		if ( ! empty( $lang_details->source_language_code ) ) {
			return new WP_Error( 'not_original', 'Resync must run on the original post', array( 'status' => 400 ) );
		}

		$stats = array(
			'strings_registered'   => 0,
			'components_processed' => 0,
			'translations_updated' => 0,
			'up_to_date'           => 0,
		);

		// Step 1: Re-register strings and clean stale for the post.
		$post_ids_to_process = array( $post_id );

		// Step 2: Also process referenced components — local scope only.
		// In global resync, components are iterated independently as
		// top-level posts so this recursion would just duplicate work.
		if ( $process_components && $this->parser->has_etch_blocks( $post ) ) {
			$blocks        = parse_blocks( $post->post_content );
			$component_ids = $this->parser->extract_component_refs( $blocks );
			foreach ( array_keys( $component_ids ) as $cid ) {
				$comp_post = get_post( (int) $cid );
				if ( $comp_post && $this->parser->has_etch_blocks( $comp_post ) ) {
					$post_ids_to_process[] = (int) $cid;
					$stats['components_processed']++;
				}
			}
		}

		foreach ( $post_ids_to_process as $pid ) {
			$values        = $this->register_and_cleanup( $pid );
			$stats['strings_registered'] += count( $values );
		}

		// Step 3: For each existing translation, sync meta + content + auto-complete.
		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type );
		if ( ! is_array( $translations ) ) {
			$translations = array();
		}

		foreach ( $translations as $translation ) {
			if ( ! empty( $translation->original ) ) {
				continue;
			}
			$translated_id = (int) ( $translation->element_id ?? 0 );
			$lang_code     = $translation->language_code ?? '';
			if ( ! $translated_id || ! $lang_code ) {
				continue;
			}

			// Copy meta.
			$this->translation_sync->copy_etch_meta( $post_id, $translated_id );

			// Apply Etch translations to content.
			$this->content_handler->apply_etch_translations( $post_id, $translated_id, $lang_code );

			// Also apply translations for components.
			foreach ( $post_ids_to_process as $pid ) {
				if ( $pid === $post_id ) {
					continue; // Already handled above.
				}
				$comp_translated_id = (int) apply_filters( 'wpml_object_id', $pid, 'wp_block', false, $lang_code );
				if ( $comp_translated_id && $comp_translated_id !== $pid ) {
					$this->translation_sync->copy_etch_meta( $pid, $comp_translated_id );
					$this->content_handler->apply_etch_translations( $pid, $comp_translated_id, $lang_code );
				}
			}

			$stats['translations_updated']++;

			// Auto-complete or force needs_update based on this post's own
			// strings only. Strict per-post: components are reconciled
			// independently in Step 4 below, so the page status reflects only
			// what belongs to the page itself.
			$complete = $this->is_translation_complete( array( $post_id ), $lang_code );
			if ( $complete ) {
				$this->auto_complete_translation( $post_id, $post_type, $translated_id, $lang_code );
				$stats['up_to_date']++;
			} else {
				$this->ensure_needs_update( $post_type, $translated_id );
			}
		}

		// Step 4: Reconcile component translations independently.
		// apply_etch_translations() above called wp_update_post() on each component
		// translation, which triggers WPML's save_post pipeline and silently resets
		// needs_update=0. The page-level loop only re-evaluates page translations,
		// so component translations need their own pass — based on each component's
		// own string completeness, not the page's combined state.
		foreach ( $post_ids_to_process as $pid ) {
			if ( $pid === $post_id ) {
				continue;
			}
			$this->reconcile_component_translations( (int) $pid );
		}

		// Clear WPML caches.
		wp_cache_delete( $post_id, 'wpml_tm' );
		wp_cache_delete( 'package_' . $post_id, 'wpml_tm' );
		if ( function_exists( 'icl_cache_clear' ) ) {
			icl_cache_clear();
		}

		Logger::info( 'Resync completed', array(
			'post_id' => $post_id,
			'stats'   => $stats,
		) );

		$this->record_last_run( 'local', $stats, $post_id );

		return array( 'success' => true, 'stats' => $stats );
	}

	/**
	 * Resync every Etch original on the site.
	 *
	 * Iterates each post once (components included as top-level posts) so
	 * there is no duplicate work. Errors on individual posts are recorded
	 * but do not abort the run.
	 *
	 * @return array{success: bool, stats: array}
	 */
	public function resync_all(): array {
		if ( get_transient( 'zs_wxe_resync_lock' ) ) {
			return array( 'skipped' => true, 'reason' => 'Resync already in progress' );
		}
		set_transient( 'zs_wxe_resync_lock', true, 120 );

		try {
			$start = microtime( true );
			$stats = array(
				'posts_processed'      => 0,
				'strings_registered'   => 0,
				'components_processed' => 0,
				'translations_updated' => 0,
				'up_to_date'           => 0,
				'errors'               => 0,
				'duration_ms'          => 0,
			);

			$post_ids = $this->find_all_etch_originals();

			foreach ( $post_ids as $pid ) {
				$result = $this->resync( $pid, false );
				if ( is_wp_error( $result ) ) {
					$stats['errors']++;
					Logger::warning( 'Resync skipped post', array(
						'post_id' => $pid,
						'reason'  => $result->get_error_code(),
					) );
					continue;
				}
				$stats['posts_processed']++;
				$stats['strings_registered']   += (int) ( $result['stats']['strings_registered'] ?? 0 );
				$stats['components_processed'] += (int) ( $result['stats']['components_processed'] ?? 0 );
				$stats['translations_updated'] += (int) ( $result['stats']['translations_updated'] ?? 0 );
				$stats['up_to_date']           += (int) ( $result['stats']['up_to_date'] ?? 0 );
			}

			$stats['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

			Logger::info( 'Resync all completed', array( 'stats' => $stats ) );

			$this->record_last_run( 'global', $stats );

			return array( 'success' => true, 'stats' => $stats );
		} finally {
			delete_transient( 'zs_wxe_resync_lock' );
		}
	}

	/**
	 * Read the persisted last-run state for the panel.
	 *
	 * Returns both scopes separately so the panel can decide what to surface.
	 * Today only the global scope drives the visible status line — local
	 * runs are silent successes by design — but the local branch is kept in
	 * storage for future use (debugging, error recovery, activity history).
	 *
	 * @return array{
	 *     local:  array{timestamp: int, stats: array, post_id: int}|null,
	 *     global: array{timestamp: int, stats: array}|null
	 * }
	 */
	public function get_last_run_status(): array {
		$value = get_option( self::LAST_RUN_OPTION );
		if ( ! is_array( $value ) ) {
			return array( 'local' => null, 'global' => null );
		}

		// Migration: old flat shape { timestamp, scope, stats, post_id }.
		// Drop it into the matching branch and return — the next write
		// will persist the new shape.
		if ( isset( $value['timestamp'] ) ) {
			$scope = (string) ( $value['scope'] ?? 'local' );
			$entry = array(
				'timestamp' => (int) $value['timestamp'],
				'stats'     => is_array( $value['stats'] ?? null ) ? $value['stats'] : array(),
			);
			if ( $scope === 'global' ) {
				return array( 'local' => null, 'global' => $entry );
			}
			$entry['post_id'] = (int) ( $value['post_id'] ?? 0 );
			return array( 'local' => $entry, 'global' => null );
		}

		return array(
			'local'  => $this->normalize_branch( $value['local'] ?? null, true ),
			'global' => $this->normalize_branch( $value['global'] ?? null, false ),
		);
	}

	/**
	 * Normalize a stored branch into the canonical shape, or null.
	 */
	private function normalize_branch( $raw, bool $with_post_id ): ?array {
		if ( ! is_array( $raw ) || empty( $raw['timestamp'] ) ) {
			return null;
		}
		$entry = array(
			'timestamp' => (int) $raw['timestamp'],
			'stats'     => is_array( $raw['stats'] ?? null ) ? $raw['stats'] : array(),
		);
		if ( $with_post_id ) {
			$entry['post_id'] = (int) ( $raw['post_id'] ?? 0 );
		}
		return $entry;
	}

	/**
	 * Aggregate translation health for the whole site.
	 *
	 * Computes how many (Etch original × non-original language) pairs are in
	 * each translation status bucket, plus the total. Drives the "X of Y
	 * translations complete" line in the Force Sync tooltip. The denominator
	 * is the total number of POSSIBLE translation pairs across the site
	 * (originals × non-original languages) — pairs that have not been
	 * started yet count as `not_translated`, so the user sees an honest
	 * picture of how much work the site has left.
	 *
	 * @return array{
	 *     total: int, complete: int, needs_update: int,
	 *     in_progress: int, waiting: int, not_translated: int
	 * }
	 */
	public function get_site_health(): array {
		global $wpdb;

		$empty = array(
			'total'          => 0,
			'complete'       => 0,
			'needs_update'   => 0,
			'in_progress'    => 0,
			'waiting'        => 0,
			'not_translated' => 0,
		);

		$default_lang = apply_filters( 'wpml_default_language', null );
		if ( ! $default_lang ) {
			return $empty;
		}

		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( empty( $active_langs ) ) {
			return $empty;
		}

		$non_original_count = max( 0, count( $active_langs ) - 1 );
		if ( $non_original_count === 0 ) {
			return $empty;
		}

		$original_count = count( $this->find_all_etch_originals() );
		if ( $original_count === 0 ) {
			return $empty;
		}

		$total = $original_count * $non_original_count;

		// Pull the (status, needs_update) tuple of every existing translation
		// of an Etch original. GROUP BY collapses identical status combos so
		// PHP only loops the few buckets that actually appear.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ts.status AS s, ts.needs_update AS nu, COUNT(*) AS cnt
			 FROM {$wpdb->prefix}icl_translations orig
			 INNER JOIN {$wpdb->posts} p
			   ON p.ID = orig.element_id
			 INNER JOIN {$wpdb->prefix}icl_translations trans
			   ON trans.trid = orig.trid
			   AND trans.source_language_code IS NOT NULL
			 LEFT JOIN {$wpdb->prefix}icl_translation_status ts
			   ON ts.translation_id = trans.translation_id
			 WHERE orig.source_language_code IS NULL
			   AND orig.language_code = %s
			   AND orig.element_type LIKE %s
			   AND p.post_status = 'publish'
			   AND p.post_content LIKE %s
			 GROUP BY ts.status, ts.needs_update",
			$default_lang,
			'post\\_%',
			'%<!-- wp:etch/%'
		) );

		$existing                = 0;
		$complete                = 0;
		$needs_update            = 0;
		$in_progress             = 0;
		$waiting                 = 0;
		$existing_not_translated = 0;

		foreach ( (array) $rows as $row ) {
			$cnt       = (int) $row->cnt;
			$existing += $cnt;
			$bucket    = TranslationDataQuery::resolve_wpml_status( (object) array(
				'status'       => $row->s,
				'needs_update' => $row->nu,
			) );
			switch ( $bucket ) {
				case 'translated':
					$complete += $cnt;
					break;
				case 'needs_update':
					$needs_update += $cnt;
					break;
				case 'in_progress':
					$in_progress += $cnt;
					break;
				case 'waiting':
					$waiting += $cnt;
					break;
				default:
					$existing_not_translated += $cnt;
					break;
			}
		}

		// Pairs with no translation row at all → also "not translated".
		$missing        = max( 0, $total - $existing );
		$not_translated = $existing_not_translated + $missing;

		return array(
			'total'          => $total,
			'complete'       => $complete,
			'needs_update'   => $needs_update,
			'in_progress'    => $in_progress,
			'waiting'        => $waiting,
			'not_translated' => $not_translated,
		);
	}

	/**
	 * Find every original Etch post across all configured post types.
	 *
	 * Returns post IDs that:
	 * - Have Etch blocks in post_content
	 * - Are originals (no source language) in the default WPML language
	 * - Belong to a translatable post type granted by pill access
	 *
	 * @return int[]
	 */
	private function find_all_etch_originals(): array {
		global $wpdb;

		$default_lang = apply_filters( 'wpml_default_language', null );
		if ( ! $default_lang ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_type
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->prefix}icl_translations t
			   ON t.element_id = p.ID
			   AND t.element_type = CONCAT('post_', p.post_type)
			 WHERE p.post_status = 'publish'
			   AND p.post_content LIKE %s
			   AND t.source_language_code IS NULL
			   AND t.language_code = %s
			 ORDER BY p.ID ASC",
			'%<!-- wp:etch/%',
			$default_lang
		) );

		if ( empty( $rows ) ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row->ID;
		}
		return $ids;
	}

	/**
	 * Persist the last-run summary for one scope, preserving the other.
	 *
	 * Storage shape: `{ local: {timestamp, stats, post_id}|null,
	 *                   global: {timestamp, stats}|null }`. Each scope is
	 * tracked independently so a routine local resync after save never
	 * overwrites the user's most recent "Resync All" snapshot.
	 */
	private function record_last_run( string $scope, array $stats, int $post_id = 0 ): void {
		$current = $this->get_last_run_status();

		if ( $scope === 'global' ) {
			$current['global'] = array(
				'timestamp' => time(),
				'stats'     => $stats,
			);
		} else {
			$current['local'] = array(
				'timestamp' => time(),
				'stats'     => $stats,
				'post_id'   => $post_id,
			);
		}

		update_option(
			self::LAST_RUN_OPTION,
			$current,
			false // Don't autoload — only read by the panel.
		);
	}

	/**
	 * Register strings and cleanup stale for a single post.
	 *
	 * @return array Current translatable values.
	 */
	private function register_and_cleanup( int $pid ): array {
		$post_obj = get_post( $pid );
		if ( ! $post_obj || ! $this->parser->has_etch_blocks( $post_obj ) ) {
			return array();
		}

		$values = $this->parser->get_translatable_values( $pid );

		$etch_package = array(
			'kind'    => StringHandler::PACKAGE_KIND,
			'name'    => $pid,
			'title'   => 'Etch Page ' . $pid,
			'post_id' => $pid,
		);

		foreach ( $values as $value ) {
			do_action( 'wpml_register_string', $value, md5( $value ), $etch_package, $value, 'LINE' );
		}

		$this->string_handler->cleanup_stale_package_strings( $pid, $values );

		// Update snapshot so MetaSync doesn't re-invalidate.
		update_post_meta( $pid, '_zs_wxe_values', $values );

		return $values;
	}

	/**
	 * Reconcile a component's own translation rows after content sync.
	 *
	 * Iterates the component's translations and forces needs_update=1 (or
	 * auto-completes) per language, scoped to the component's own strings.
	 * This protects against WPML's save_post pipeline silently clearing
	 * needs_update when apply_etch_translations() updates the translated post.
	 */
	private function reconcile_component_translations( int $component_id ): void {
		$trid = (int) apply_filters( 'wpml_element_trid', null, $component_id, 'post_wp_block' );
		if ( ! $trid ) {
			return;
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_wp_block' );
		if ( ! is_array( $translations ) ) {
			return;
		}

		foreach ( $translations as $translation ) {
			if ( ! empty( $translation->original ) ) {
				continue;
			}
			$translated_id = (int) ( $translation->element_id ?? 0 );
			$lang_code     = $translation->language_code ?? '';
			if ( ! $translated_id || ! $lang_code ) {
				continue;
			}

			if ( $this->is_translation_complete( array( $component_id ), $lang_code ) ) {
				$this->auto_complete_translation( $component_id, 'wp_block', $translated_id, $lang_code );
			} else {
				$this->ensure_needs_update( 'wp_block', $translated_id );
			}
		}
	}

	/**
	 * Check if all Etch strings for the given posts are translated for a language.
	 *
	 * Strings that WPML itself considers "not translatable" (pure numbers, CSS
	 * colors, CSS lengths) — plus our own extension for whitespace-only, pure
	 * symbols and pure punctuation — are excluded from the count. This matches
	 * WPML's own job-assembly behaviour: those fields get auto-completed with
	 * the source value (`field_translate=0, field_finished=1`) and never reach
	 * ATE. Counting them as "pending" produced spurious `needs_update` reports
	 * on pages with numeric UI labels ("01", "02") or glyphs ("→").
	 */
	private function is_translation_complete( array $post_ids, string $lang ): bool {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return true;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from count.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.value, st.id AS translation_id
			 FROM {$wpdb->prefix}icl_strings s
			 JOIN {$wpdb->prefix}icl_string_packages p ON s.string_package_id = p.ID
			 LEFT JOIN {$wpdb->prefix}icl_string_translations st
			     ON st.string_id = s.id AND st.language = %s AND st.status = 10
			 WHERE p.kind = %s AND p.post_id IN ({$placeholders})",
			$lang,
			StringHandler::PACKAGE_KIND,
			...$post_ids
		) );

		if ( empty( $rows ) ) {
			return true;
		}

		$total      = 0;
		$translated = 0;
		foreach ( $rows as $r ) {
			if ( StringHandler::is_not_translatable( (string) $r->value ) ) {
				continue;
			}
			$total++;
			if ( $r->translation_id ) {
				$translated++;
			}
		}

		return $total === 0 || $translated >= $total;
	}

	/**
	 * Force needs_update=1 if translation is incorrectly marked complete.
	 */
	private function ensure_needs_update( string $post_type, int $translated_id ): void {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}icl_translation_status ts
			 JOIN {$wpdb->prefix}icl_translations t ON ts.translation_id = t.translation_id
			 SET ts.needs_update = 1
			 WHERE t.element_id = %d AND t.element_type = %s
			   AND ts.status = 10 AND ts.needs_update = 0",
			$translated_id,
			'post_' . $post_type
		) );
		if ( false === $result ) {
			Logger::warning( 'Failed to set needs_update', array(
				'translated_id' => $translated_id,
				'post_type'     => $post_type,
			) );
		}
	}

	/**
	 * Mark a translation as complete if it's not currently in progress.
	 */
	private function auto_complete_translation( int $original_id, string $post_type, int $translated_id, string $lang ): bool {
		global $wpdb;

		$translation_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT translation_id FROM {$wpdb->prefix}icl_translations
			 WHERE element_id = %d AND element_type = %s",
			$translated_id,
			'post_' . $post_type
		) );

		if ( ! $translation_id ) {
			return false;
		}

		$status_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT rid, status, needs_update FROM {$wpdb->prefix}icl_translation_status
			 WHERE translation_id = %d",
			$translation_id
		) );

		if ( ! $status_row ) {
			return false;
		}

		// Don't touch in-progress translations (active ATE jobs).
		if ( (int) $status_row->status === 2 ) {
			return false;
		}

		// Already complete and up to date — nothing to do.
		if ( (int) $status_row->status === 10 && (int) $status_row->needs_update === 0 ) {
			return false;
		}

		// Calculate current MD5 to prevent WPML from re-triggering needs_update.
		$md5 = '';
		if ( class_exists( '\WPML_TM_Action_Helper' ) ) {
			$helper = new \WPML_TM_Action_Helper();
			$md5    = $helper->post_md5( get_post( $original_id ) );
		}

		$update_data = array(
			'status'       => 10,
			'needs_update' => 0,
		);
		if ( $md5 ) {
			$update_data['md5'] = $md5;
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'icl_translation_status',
			$update_data,
			array( 'rid' => (int) $status_row->rid )
		);
		if ( false === $result ) {
			Logger::warning( 'Failed to auto-complete translation status', array(
				'original_id'   => $original_id,
				'translated_id' => $translated_id,
				'rid'           => (int) $status_row->rid,
			) );
		}

		return true;
	}
}
