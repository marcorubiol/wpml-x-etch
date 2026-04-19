<?php
/**
 * AI translation handler: orchestrates string extraction, AI calls, and WPML writes.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\AI;

use WP_Error;
use WpmlXEtch\Admin\PanelConfig;
use WpmlXEtch\Admin\ResyncHandler;
use WpmlXEtch\Admin\TranslationJobManager;
use WpmlXEtch\Etch\ComponentParser;
use WpmlXEtch\WPML\StringHandler;
use WpmlXEtch\Utils\Logger;
use WpmlXEtch\WPML\ContentTranslationHandler;

class AiTranslationHandler {

	private readonly ComponentParser $parser;
	private readonly StringHandler $string_handler;
	private readonly ContentTranslationHandler $content_handler;
	private readonly AiSettings $settings;
	private readonly AiClient $client;
	private readonly ResyncHandler $resync_handler;
	private readonly TranslationJobManager $job_manager;
	private readonly PanelConfig $config;

	public function __construct(
		ComponentParser $parser,
		StringHandler $string_handler,
		ContentTranslationHandler $content_handler,
		AiSettings $settings,
		AiClient $client,
		ResyncHandler $resync_handler,
		TranslationJobManager $job_manager,
		PanelConfig $config
	) {
		$this->parser          = $parser;
		$this->string_handler  = $string_handler;
		$this->content_handler = $content_handler;
		$this->settings        = $settings;
		$this->client          = $client;
		$this->resync_handler  = $resync_handler;
		$this->job_manager     = $job_manager;
		$this->config          = $config;
	}

	/**
	 * Translate a post to a single language via AI.
	 */
	public function translate( int $post_id, string $target_lang, int $component_id = 0, bool $force = false ): array|WP_Error {
		// Gate check.
		$access = $this->config->get_pill_access();
		if ( empty( $access['ai'] ) ) {
			return new WP_Error( 'license_required', 'AI translation requires Pro license', array( 'status' => 403 ) );
		}

		if ( ! $this->settings->is_configured() ) {
			return new WP_Error( 'not_configured', 'AI provider not configured', array( 'status' => 400 ) );
		}

		$effective_id = $component_id ?: $post_id;
		$post         = get_post( $effective_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
		}

		// 1. Resync first: register strings, clean stale, ensure WPML state is fresh.
		$this->resync_handler->resync( $effective_id );

		// 2. Extract translatable values and find their WPML string IDs.
		$values = $this->parser->get_translatable_values( $effective_id );

		global $wpdb;
		$untranslated = array();
		$string_map   = array();

		foreach ( $values as $value ) {
			$string_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT s.id FROM {$wpdb->prefix}icl_strings s
				 JOIN {$wpdb->prefix}icl_string_packages p ON s.string_package_id = p.ID
				 WHERE p.kind = %s AND p.post_id = %d AND s.name = %s",
				StringHandler::PACKAGE_KIND,
				$effective_id,
				md5( $value )
			) );

			if ( ! $string_id ) {
				continue;
			}

			$string_map[ $value ] = (int) $string_id;

			if ( $force ) {
				$untranslated[ $value ] = (int) $string_id;
				continue;
			}

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}icl_string_translations
				 WHERE string_id = %d AND language = %s AND status = 10",
				$string_id,
				$target_lang
			) );

			if ( null === $existing ) {
				$untranslated[ $value ] = (int) $string_id;
			}
		}

		// Always include the post title in the AI batch — it's a job field,
		// not a package string, so it's handled separately via the
		// translations map passed to complete_job_via_wpml().
		$title = $post->post_title;
		$all_strings_for_ai = array_keys( $untranslated );
		if ( $title && '' !== trim( $title ) ) {
			$all_strings_for_ai[] = $title;
		}

		if ( empty( $all_strings_for_ai ) ) {
			return array( 'success' => true, 'translated_count' => 0, 'skipped_count' => count( $string_map ) );
		}

		// 3. Call AI to translate (strings + title in one batch).
		$source_lang_name = $this->get_language_name( apply_filters( 'wpml_default_language', null ) );
		$target_lang_name = $this->get_language_name( $target_lang );

		$result = $this->client->translate(
			$all_strings_for_ai,
			$source_lang_name,
			$target_lang_name,
			array(
				'tone'       => $this->settings->get_tone(),
				'glossary'   => $this->settings->get_glossary(),
				'page_title' => $title,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// 4. Write string translations to WPML string tables.
		$translated_count = 0;
		$failed_count     = 0;
		foreach ( $result as $original => $translated ) {
			if ( ! isset( $untranslated[ $original ] ) ) {
				continue; // Title or extra keys — not a package string.
			}
			if ( $this->write_string_translation( $untranslated[ $original ], $target_lang, $translated ) ) {
				$translated_count++;
			} else {
				$failed_count++;
			}
		}

		// Count the title as translated if it came back.
		if ( isset( $result[ $title ] ) ) {
			$translated_count++;
		}

		if ( $failed_count > 0 && 0 === $translated_count ) {
			return new WP_Error( 'write_failed', 'All translations failed to save to the database', array( 'status' => 500 ) );
		}

		// 5. Resync: apply translations to post_content.
		$this->resync_handler->resync( $effective_id );

		// 6. Complete the translation job via WPML's native path.
		// Pass the AI translations map so complete_job_via_wpml() can fill
		// the title and other job fields with translated values.
		$this->job_manager->refresh_job_for_post( $effective_id, $target_lang, true, $result );

		$response = array(
			'success'          => true,
			'translated_count' => $translated_count,
			'skipped_count'    => count( $string_map ) - count( $untranslated ),
		);

		if ( $failed_count > 0 ) {
			$response['failed_count'] = $failed_count;
		}

		return $response;
	}

	/**
	 * Translate a post to all pending languages.
	 */
	public function translate_all( int $post_id, int $component_id = 0, bool $force = false ): array|WP_Error {
		// Increase time limit for multi-language translation.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$extended = @set_time_limit( 120 );
			if ( ! $extended ) {
				Logger::warning( 'Could not extend time limit for multi-language translation' );
			}
		}

		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( empty( $active_langs ) || ! is_array( $active_langs ) ) {
			return new WP_Error( 'no_languages', 'No active languages', array( 'status' => 500 ) );
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$results      = array();

		foreach ( $active_langs as $code => $lang ) {
			if ( $code === $default_lang ) {
				continue;
			}

			$result = $this->translate( $post_id, $code, $component_id, $force );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'lang' => $code, 'error' => $result->get_error_message() );
				continue;
			}

			$results[] = array(
				'lang'             => $code,
				'translated_count' => $result['translated_count'],
				'skipped_count'    => $result['skipped_count'],
			);
		}

		return array( 'success' => true, 'languages' => $results );
	}

	/**
	 * Translate a JSON loop's strings to a single language via AI.
	 */
	public function translate_loop( string $loop_id, string $target_lang, bool $force = false ): array|\WP_Error {
		$access = $this->config->get_pill_access();
		if ( empty( $access['ai'] ) ) {
			return new \WP_Error( 'license_required', 'AI translation requires Pro license', array( 'status' => 403 ) );
		}

		if ( ! $this->settings->is_configured() ) {
			return new \WP_Error( 'not_configured', 'AI provider not configured', array( 'status' => 400 ) );
		}

		// Resolve loop name from the etch_loops option.
		// etch_loops is keyed by loop ID: ['etch1r1' => ['name' => '...', ...]]
		$loops = get_option( 'etch_loops', array() );
		if ( ! is_array( $loops ) || ! isset( $loops[ $loop_id ] ) ) {
			return new \WP_Error( 'loop_not_found', 'JSON loop not found', array( 'status' => 404 ) );
		}

		$loop_name = $loops[ $loop_id ]['name'] ?? $loop_id;

		// Find all registered strings for this loop.
		global $wpdb;
		$context = 'Etch JSON Loops';
		$name_prefix = $loop_name . ' › ';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$strings = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.value, s.name FROM {$wpdb->prefix}icl_strings s
			 WHERE s.context = %s AND s.name LIKE %s",
			$context,
			$wpdb->esc_like( $name_prefix ) . '%'
		) );

		if ( empty( $strings ) ) {
			return array( 'success' => true, 'translated_count' => 0, 'skipped_count' => 0 );
		}

		$untranslated = array();
		$skipped      = 0;

		foreach ( $strings as $str ) {
			if ( $force ) {
				$untranslated[ $str->value ] = (int) $str->id;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}icl_string_translations
				 WHERE string_id = %d AND language = %s AND status = 10",
				$str->id,
				$target_lang
			) );

			if ( null === $existing ) {
				$untranslated[ $str->value ] = (int) $str->id;
			} else {
				$skipped++;
			}
		}

		if ( empty( $untranslated ) ) {
			return array( 'success' => true, 'translated_count' => 0, 'skipped_count' => $skipped );
		}

		// Call AI. URLs are resolved at runtime by LoopTranslator, not by AI.
		$source_lang_name = $this->get_language_name( apply_filters( 'wpml_default_language', null ) );
		$target_lang_name = $this->get_language_name( $target_lang );

		$result = $this->client->translate(
			array_keys( $untranslated ),
			$source_lang_name,
			$target_lang_name,
			array(
				'tone'       => $this->settings->get_tone(),
				'glossary'   => $this->settings->get_glossary(),
				'page_title' => $loop_name . ' (JSON Loop)',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Write translations.
		$translated_count = 0;
		$failed_count     = 0;
		foreach ( $result as $original => $translated ) {
			if ( ! isset( $untranslated[ $original ] ) ) {
				continue;
			}
			if ( $this->write_string_translation( $untranslated[ $original ], $target_lang, $translated ) ) {
				$translated_count++;
			} else {
				$failed_count++;
			}
		}

		if ( $failed_count > 0 && 0 === $translated_count ) {
			return new \WP_Error( 'write_failed', 'All translations failed to save', array( 'status' => 500 ) );
		}

		$response = array(
			'success'          => true,
			'translated_count' => $translated_count,
			'skipped_count'    => $skipped,
		);

		if ( $failed_count > 0 ) {
			$response['failed_count'] = $failed_count;
		}

		return $response;
	}

	/**
	 * Translate a JSON loop to all active languages.
	 */
	public function translate_loop_all( string $loop_id, bool $force = false ): array|\WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 120 );
		}

		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( empty( $active_langs ) || ! is_array( $active_langs ) ) {
			return new \WP_Error( 'no_languages', 'No active languages', array( 'status' => 500 ) );
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$results      = array();

		foreach ( $active_langs as $code => $lang ) {
			if ( $code === $default_lang ) {
				continue;
			}

			$result = $this->translate_loop( $loop_id, $code, $force );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'lang' => $code, 'error' => $result->get_error_message() );
				continue;
			}

			$results[] = array(
				'lang'             => $code,
				'translated_count' => $result['translated_count'],
				'skipped_count'    => $result['skipped_count'],
			);
		}

		return array( 'success' => true, 'languages' => $results );
	}

	private function write_string_translation( int $string_id, string $lang, string $value ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_string_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE string_id = %d AND language = %s",
			$string_id,
			$lang
		) );

		$data = array(
			'value'         => $value,
			'status'        => 10,
			'translator_id' => get_current_user_id(),
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->update( $table, $data, array( 'id' => $existing_id ) );
		} else {
			$data['string_id'] = $string_id;
			$data['language']  = $lang;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $data );
		}

		if ( false === $result ) {
			Logger::error( 'Failed to write string translation', array(
				'string_id' => $string_id,
				'language'  => $lang,
				'db_error'  => $wpdb->last_error,
			) );
			return false;
		}

		return true;
	}

	private function get_language_name( string $code ): string {
		$languages = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( is_array( $languages ) && isset( $languages[ $code ] ) ) {
			return $languages[ $code ]['native_name'] ?? $code;
		}
		return $code;
	}

	// --- REST endpoint handlers (delegated from routes) ---

	public function handle_get_settings(): array {
		return $this->settings->get_settings_for_js();
	}

	public function handle_save_settings( array $data ): array {
		$this->settings->save_settings( $data );
		return $this->settings->get_settings_for_js();
	}

	public function handle_test_connection(): array|WP_Error {
		return $this->settings->test_connection();
	}
}
