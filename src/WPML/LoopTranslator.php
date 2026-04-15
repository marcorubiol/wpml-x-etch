<?php
/**
 * Translates JSON loop data via WPML String Translation.
 *
 * Registers translatable text values from JSON loops as WPML strings on init,
 * and translates them at render time by filtering the etch_loops option.
 *
 * Fields are filtered via an allowlist (filterable with
 * 'zs_wxe_loop_translatable_fields') with a heuristic fallback for unknown fields.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\WPML;

use WpmlXEtch\Core\SubscriberInterface;

class LoopTranslator implements SubscriberInterface {

	private const CONTEXT = 'Etch JSON Loops';

	/**
	 * Loop IDs that are managed by this plugin's own integration code and
	 * must NEVER be registered with WPML String Translation. Their data is
	 * generated dynamically from authoritative sources (e.g. WPML's own
	 * language list) — re-registering would be circular and leak our
	 * internal strings into the user-facing "Translate all JSON Loops"
	 * flow, polluting it with values the user can't meaningfully edit.
	 */
	private const RESERVED_LOOP_IDS = array(
		'wpml-languages',
	);

	private const DEFAULT_TRANSLATABLE_FIELDS = array(
		'label',
		'title',
		'content',
		'description',
		'text',
		'name',
		'alt',
		'heading',
		'body',
		'subtitle',
		'caption',
		'excerpt',
		'summary',
	);

	public static function getSubscribedEvents(): array {
		return array(
			array( 'init', 'register_loop_strings', 15 ),
			array( 'option_etch_loops', 'translate_loops' ),
		);
	}

	/**
	 * Register translatable string values from JSON loops with WPML.
	 */
	public function register_loop_strings(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! defined( 'REST_REQUEST' ) ) {
			return;
		}
		if ( ! function_exists( 'icl_register_string' ) ) {
			return;
		}

		$loops = get_option( 'etch_loops', array() );
		if ( ! is_array( $loops ) ) {
			return;
		}

		// One-time cleanup of strings registered before reserved-loop filtering existed.
		$this->maybe_cleanup_reserved_loop_strings( $loops );

		// Collect all current string names so we can clean up stale ones.
		$current_names = array();

		foreach ( $loops as $loop_id => $loop ) {
			if ( in_array( $loop_id, self::RESERVED_LOOP_IDS, true ) ) {
				continue;
			}

			$type = $loop['config']['type'] ?? '';
			if ( 'json' !== $type ) {
				continue;
			}

			$data = $loop['config']['data'] ?? array();
			if ( ! is_array( $data ) ) {
				continue;
			}

			$loop_name          = $loop['name'] ?? $loop_id;
			$translatable_fields = $this->get_translatable_fields( $loop_id );

			$this->walk_items( $data, $loop_id, $loop_name, '', $translatable_fields, function ( string $name, string $value ) use ( &$current_names ) {
				icl_register_string( self::CONTEXT, $name, $value );
				$current_names[] = $name;
			} );
		}

		// Remove orphaned strings no longer present in any loop.
		$this->cleanup_stale_loop_strings( $current_names );
	}

	/**
	 * Delete strings from icl_strings (+ translations) that are registered
	 * under the JSON Loops context but no longer exist in the current loop data.
	 *
	 * @param string[] $current_names Names of strings that are still valid.
	 */
	private function cleanup_stale_loop_strings( array $current_names ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$registered = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name FROM {$wpdb->prefix}icl_strings WHERE context = %s",
			self::CONTEXT
		) );

		if ( ! $registered ) {
			return;
		}

		$current_set = array_flip( $current_names );
		$stale_ids   = array();

		foreach ( $registered as $row ) {
			if ( ! isset( $current_set[ $row->name ] ) ) {
				$stale_ids[] = (int) $row->id;
			}
		}

		if ( empty( $stale_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $stale_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}icl_string_translations WHERE string_id IN ({$placeholders})",
			...$stale_ids
		) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}icl_strings WHERE id IN ({$placeholders})",
			...$stale_ids
		) );
	}

	/**
	 * Filter the etch_loops option to translate JSON loop data.
	 *
	 * @param mixed $loops The raw option value.
	 * @return mixed
	 */
	public function translate_loops( $loops ) {
		if ( ! is_array( $loops ) ) {
			return $loops;
		}

		// Never translate in editing contexts — loop data must stay in the
		// source language to prevent corruption when Etch saves.
		if (
			isset( $_GET['etch'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| is_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| wp_doing_ajax()
		) {
			return $loops;
		}

		// Language switcher loop: refresh URLs to point to the current page
		// in each language. Runs for ALL languages (including default)
		// because the URLs are always dynamic.
		if ( did_action( 'wp' ) && isset( $loops['wpml-languages']['config']['data'] ) && is_array( $loops['wpml-languages']['config']['data'] ) ) {
			$fresh = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( ! empty( $fresh ) && is_array( $fresh ) ) {
				$loops['wpml-languages']['config']['data'] = array_values( $fresh );
			}
		}

		$current = apply_filters( 'wpml_current_language', null );
		$default = apply_filters( 'wpml_default_language', null );
		if ( ! $current || $current === $default ) {
			return $loops;
		}

		// Single batch query: load ALL translations for this context + language.
		$translation_map = $this->load_translation_map( $current );

		foreach ( $loops as $loop_id => &$loop ) {
			if ( in_array( $loop_id, self::RESERVED_LOOP_IDS, true ) ) {
				continue;
			}

			$type = $loop['config']['type'] ?? '';
			if ( 'json' !== $type ) {
				continue;
			}

			$data = $loop['config']['data'] ?? array();
			if ( ! is_array( $data ) ) {
				continue;
			}

			$loop_name          = $loop['name'] ?? $loop_id;
			$translatable_fields = $this->get_translatable_fields( $loop_id );

			$loop['config']['data'] = $this->translate_items( $data, $loop_id, $loop_name, $translatable_fields, $translation_map );
		}
		unset( $loop );

		return $loops;
	}

	/**
	 * Get the merged translatable fields for a given loop.
	 *
	 * @param string $loop_id The loop preset ID.
	 * @return array<string> Field names considered translatable, or empty if none defined.
	 */
	private function get_translatable_fields( string $loop_id ): array {
		$map = apply_filters(
			'zs_wxe_loop_translatable_fields',
			array( '*' => self::DEFAULT_TRANSLATABLE_FIELDS ),
			$loop_id
		);

		$fields = array();

		if ( isset( $map['*'] ) && is_array( $map['*'] ) ) {
			$fields = $map['*'];
		}

		if ( isset( $map[ $loop_id ] ) && is_array( $map[ $loop_id ] ) ) {
			$fields = array_merge( $fields, $map[ $loop_id ] );
		}

		return array_unique( $fields );
	}

	/**
	 * Determine if a field should be translated.
	 *
	 * @param string        $field              The field name.
	 * @param string        $value              The field value.
	 * @param array<string> $translatable_fields Allowlisted field names.
	 * @return bool
	 */
	private function should_translate_field( string $field, string $value, array $translatable_fields ): bool {
		if ( in_array( $field, $translatable_fields, true ) ) {
			return true;
		}

		return $this->is_translatable_value( $value );
	}

	/**
	 * Heuristic check: does this value look like human-readable text?
	 *
	 * @param string $value The string value to inspect.
	 * @return bool
	 */
	private function is_translatable_value( string $value ): bool {
		if ( strlen( $value ) <= 3 ) {
			return false;
		}

		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		if ( preg_match( '#^/[^ ]*$#', $value ) ) {
			return false;
		}

		if ( is_numeric( $value ) ) {
			return false;
		}

		if ( in_array( $value, array( 'true', 'false' ), true ) ) {
			return false;
		}

		if ( filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Recursively translate string values in loop items.
	 *
	 * @param array         $items              The loop data items.
	 * @param string        $loop_id            The loop preset ID.
	 * @param string        $loop_name          The loop display name.
	 * @param array<string> $translatable_fields Allowlisted field names.
	 * @param string        $prefix             Key prefix for nested items.
	 * @return array
	 */
	private function translate_items( array $items, string $loop_id, string $loop_name, array $translatable_fields, array $translation_map, string $prefix = '' ): array {
		foreach ( $items as $index => &$item ) {
			if ( is_array( $item ) ) {
				foreach ( $item as $field => &$value ) {
					if ( is_string( $value ) && '' !== $value ) {
						if ( $this->is_url_field( $field ) && str_starts_with( $value, '#' ) ) {
							// Anchor links: leave as-is (element IDs are not translated).
							continue;
						} elseif ( $this->is_url_field( $field ) && ! $this->is_external_url( $value ) ) {
							// Internal URL: resolve to translated permalink.
							$value = $this->resolve_translated_url( $value );
						} elseif ( $this->is_url_field( $field ) || $this->should_translate_field( (string) $field, $value, $translatable_fields ) ) {
							// External URL or translatable text: use translation map.
							$name = self::build_string_name( $loop_name, $prefix . substr( md5( $value ), 0, 8 ) . '.' . $field );
							$value = $translation_map[ $name ] ?? $value;
						}
					} elseif ( is_array( $value ) ) {
						$value = $this->translate_items( $value, $loop_id, $loop_name, $translatable_fields, $translation_map, $prefix . $index . '.' . $field . '.' );
					}
				}
				unset( $value );
			} elseif ( is_string( $item ) && '' !== $item ) {
				if ( $this->is_translatable_value( $item ) ) {
					$name = self::build_string_name( $loop_name, $prefix . substr( md5( $item ), 0, 8 ) );
					$item = $translation_map[ $name ] ?? $item;
				}
			}
		}
		unset( $item );

		return $items;
	}

	/**
	 * Walk items recursively, calling $callback for each translatable string value.
	 *
	 * @param array         $items              The loop data items.
	 * @param string        $loop_id            The loop preset ID.
	 * @param string        $loop_name          The loop display name.
	 * @param string        $prefix             Key prefix for nested items.
	 * @param array<string> $translatable_fields Allowlisted field names.
	 * @param callable      $callback           fn( string $name, string $value ).
	 */
	private function walk_items( array $items, string $loop_id, string $loop_name, string $prefix, array $translatable_fields, callable $callback ): void {
		foreach ( $items as $index => $item ) {
			if ( is_array( $item ) ) {
				foreach ( $item as $field => $value ) {
					if ( is_string( $value ) && '' !== $value ) {
						if ( $this->is_url_field( $field ) && $this->is_external_url( $value ) ) {
							// External URLs are registered for manual translation.
							$name = self::build_string_name( $loop_name, $prefix . substr( md5( $value ), 0, 8 ) . '.' . $field );
							$callback( $name, $value );
						} elseif ( $this->is_url_field( $field ) ) {
							// Internal URLs and anchors: skip — resolved at runtime or left as-is.
							continue;
						} elseif ( $this->should_translate_field( (string) $field, $value, $translatable_fields ) ) {
							$name = self::build_string_name( $loop_name, $prefix . substr( md5( $value ), 0, 8 ) . '.' . $field );
							$callback( $name, $value );
						}
					} elseif ( is_array( $value ) ) {
						$this->walk_items( $value, $loop_id, $loop_name, $prefix . $index . '.' . $field . '.', $translatable_fields, $callback );
					}
				}
			} elseif ( is_string( $item ) && '' !== $item ) {
				if ( $this->is_translatable_value( $item ) ) {
					$name = self::build_string_name( $loop_name, $prefix . substr( md5( $item ), 0, 8 ) );
					$callback( $name, $item );
				}
			}
		}
	}

	/**
	 * Load all translations for the JSON Loops context in one batch query.
	 *
	 * @return array<string, string> Map of string name → translated value.
	 */
	private function load_translation_map( string $lang ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.name, st.value
			 FROM {$wpdb->prefix}icl_string_translations st
			 JOIN {$wpdb->prefix}icl_strings s ON st.string_id = s.id
			 WHERE s.context = %s AND st.language = %s AND st.status = 10",
			self::CONTEXT,
			$lang
		) );

		$map = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$map[ $row->name ] = $row->value;
			}
		}

		return $map;
	}

	/** URL field names resolved to translated permalinks at runtime. */
	private const URL_FIELDS = array( 'url', 'href', 'link' );

	private function is_url_field( string $field ): bool {
		return in_array( strtolower( $field ), self::URL_FIELDS, true );
	}

	/**
	 * Check if a URL points to an external domain.
	 */
	private function is_external_url( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return false; // Relative URL — internal.
		}
		$site_host = wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
		return $parsed['host'] !== $site_host;
	}

	/**
	 * Resolve an internal URL to its translated equivalent.
	 *
	 * Safe to call only in frontend context (translate_loops guards ensure
	 * this never runs in admin, REST, AJAX, or Etch editor).
	 */
	private function resolve_translated_url( string $url ): string {
		if ( str_starts_with( $url, '#' ) ) {
			return $url;
		}

		$site_url  = trailingslashit( (string) get_option( 'home' ) );
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );

		$parsed      = wp_parse_url( $url );
		$is_absolute = ! empty( $parsed['scheme'] );
		$path        = '';

		if ( $is_absolute ) {
			$url_host = $parsed['host'] ?? '';
			if ( $url_host && $url_host !== $site_host ) {
				return $url;
			}
			$path = $parsed['path'] ?? '/';
		} elseif ( str_starts_with( $url, '/' ) ) {
			$path = $url;
		} else {
			return $url;
		}

		$path = trailingslashit( $path );

		// Home page.
		if ( '/' === $path ) {
			$translated_home = apply_filters( 'wpml_home_url', $site_url );
			return $is_absolute ? $translated_home : ( wp_parse_url( $translated_home, PHP_URL_PATH ) ?: '/' );
		}

		// Find post by path.
		$post_id = url_to_postid( $site_url . ltrim( $path, '/' ) );
		if ( ! $post_id ) {
			return $url;
		}

		$current_lang  = apply_filters( 'wpml_current_language', null );
		$translated_id = (int) apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), true, $current_lang );

		if ( ! $translated_id || $translated_id === $post_id ) {
			return $url;
		}

		$translated_permalink = get_permalink( $translated_id );
		if ( ! $translated_permalink ) {
			return $url;
		}

		if ( ! $is_absolute ) {
			return wp_parse_url( $translated_permalink, PHP_URL_PATH ) ?: $url;
		}

		$fragment = ! empty( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';
		$query    = ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '';

		return $translated_permalink . $query . $fragment;
	}

	/**
	 * Build a human-readable string name.
	 *
	 * Example: "Basic Nav › 1.label"
	 */
	private static function build_string_name( string $loop_name, string $path ): string {
		return $loop_name . ' › ' . $path;
	}

	/**
	 * One-time cleanup: delete strings registered for reserved loops by
	 * earlier versions of this class. Runs once per site, gated by an option.
	 *
	 * Reserved loops (e.g. wpml-languages) host data managed by external
	 * sources and shouldn't have their values living in WPML String
	 * Translation. Before this filter existed they were registered there.
	 * This wipes those legacy entries so the user's "Translate all JSON
	 * Loops" view stops showing language names.
	 */
	private function maybe_cleanup_reserved_loop_strings( array $loops ): void {
		$option_key = 'zs_wxe_loop_translator_cleanup_v1';
		if ( get_option( $option_key, false ) ) {
			return;
		}

		global $wpdb;

		foreach ( self::RESERVED_LOOP_IDS as $loop_id ) {
			if ( ! isset( $loops[ $loop_id ] ) ) {
				continue;
			}

			$loop_name = $loops[ $loop_id ]['name'] ?? $loop_id;
			$like      = $wpdb->esc_like( $loop_name . ' › ' ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$string_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}icl_strings WHERE context = %s AND name LIKE %s",
				self::CONTEXT,
				$like
			) );

			if ( empty( $string_ids ) ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}icl_string_translations WHERE string_id IN ({$placeholders})",
				...$string_ids
			) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}icl_strings WHERE id IN ({$placeholders})",
				...$string_ids
			) );
		}

		update_option( $option_key, 1, false );
	}
}
