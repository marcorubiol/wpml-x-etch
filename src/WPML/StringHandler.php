<?php
/**
 * WPML string registration and cleanup handler.
 *
 * Queries icl_strings, icl_string_translations, icl_string_packages,
 * and icl_translate directly. WPML's string API (icl_register_string /
 * wpml_register_string) handles registration, but provides no way to
 * selectively delete strings or filter by package kind, so direct
 * queries are necessary for cleanup.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\WPML;

use WpmlXEtch\Core\SubscriberInterface;
use WpmlXEtch\Utils\Logger;

/**
 * Handles WPML string registration and cleanup.
 */
class StringHandler implements SubscriberInterface {

	private readonly \WpmlXEtch\Etch\ComponentParser $parser;

	public function __construct( \WpmlXEtch\Etch\ComponentParser $parser ) {
		$this->parser = $parser;
	}

	public static function getSubscribedEvents(): array {
		return array(
			array( 'init', 'register_strings', 30 ),
			array( 'wpml_page_builder_register_strings', 'register_post_strings', 20, 2 ),
			array( 'wpml_tm_translation_job_data', 'exclude_wp_block_title', 10, 2 ),
		);
	}

	/**
	 * Exclude post_title from ATE jobs for wp_block posts.
	 *
	 * Component names are internal identifiers, not user-facing content.
	 * Same pattern WPML uses for wp_template/wp_template_part.
	 */
	public function exclude_wp_block_title( array $package, \WP_Post $post ): array {
		if ( 'wp_block' === $post->post_type ) {
			unset( $package['contents']['title'] );
		}
		return $package;
	}

	/** Register loop and style names as translatable WPML strings. */
	public function register_strings(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! defined( 'REST_REQUEST' ) ) {
			return;
		}
		if ( ! function_exists( 'icl_register_string' ) ) {
			return;
		}

		foreach ( array( 'loop' => 'etch_loops', 'style' => 'etch_styles' ) as $prefix => $option ) {
			$items = get_option( $option, array() );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $key => $item ) {
				if ( ! empty( $item['name'] ) ) {
					icl_register_string( 'Etch', $prefix . '_name_' . $key, $item['name'] );
				}
			}
		}
	}

	/** Register static builder panel UI strings with WPML String Translation. */
	public function register_ui_strings(): void {
		if ( ! function_exists( 'icl_register_string' ) ) {
			return;
		}

		$strings = array(
			'Saving before we proceed.',
			'Still saving. This takes a moment.',
			'Preparing %s translation.',
			'Waiting for ATE.',
			'Opening editor.',
			'Save failed. Try again.',
			"ATE didn't respond in time. Try again.",
			'HTTP %s error. Try again.',
			'Nothing to translate.',
			'Could not load data.',
			'Save timeout',
			'WPML × Etch',
			'Translations',
			'Back to Builder',
			'Page',
			'Current Context',
			'Default Language',
			'Components',
			'Filters',
			'Languages',
			'No languages configured.',
			'Translation',
			'Status',
			'Quick WPML Access',
			'String Translation',
			'Translation Queue',
			'Search',
			'Search by title…',
			'Select a language to translate',
			"For security reasons, WPML's translation editor opens in a new secure tab.",
			'Complete',
			'Needs Update',
			'In Progress',
			'Not Translated',
			'Translated',
			'Upgrade to Pro to browse all content',
			'Templates',
		);

		foreach ( $strings as $string ) {
			icl_register_string( 'wpml-x-etch', $string, $string );
		}
	}

	/**
	 * Self-managed string registration.
	 *
	 * Registers only real translatable strings — dynamic expressions are never
	 * registered. This replaces WPML's auto-extraction (wpml-config.xml has
	 * translate="0" for all Etch blocks).
	 */
	public const PACKAGE_KIND = 'Etch';

	/**
	 * Whether a string value should be considered "not translatable".
	 *
	 * Delegates to WPML's own public utility (numeric, CSS color, CSS length)
	 * and extends it with a gap WPML does not cover — whitespace-only values,
	 * pure Unicode symbols, and pure punctuation. This matches WPML's job
	 * assembly behaviour: fields that return true here get auto-completed
	 * with the source value and are never sent to ATE.
	 *
	 * Callers should use this to exclude non-translatable strings from
	 * completeness counts — we do NOT filter at registration time, because
	 * WPML itself registers these strings into `icl_strings` by design.
	 */
	public static function is_not_translatable( string $value ): bool {
		if ( class_exists( '\WPML_String_Functions' ) && \WPML_String_Functions::is_not_translatable( $value ) ) {
			return true;
		}

		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return true;
		}

		// Pure Unicode symbol + punctuation + whitespace — e.g. "→", "•", "— —", "…".
		// WPML_String_Functions does not cover these; they show up routinely in
		// UI chrome (list bullets, arrows, separators) and produce spurious
		// "needs update" reports when counted as pending translations.
		if ( preg_match( '/^[\p{S}\p{P}\s]+$/u', $trimmed ) ) {
			return true;
		}

		return false;
	}

	/** Check if WPML String Translation is active (tables exist). */
	private static function is_string_translation_active(): bool {
		return defined( 'WPML_ST_VERSION' );
	}

	public function register_post_strings( mixed $post, array $package_data ): void {
		if ( ! self::is_string_translation_active() ) {
			return;
		}

		if ( empty( $package_data['post_id'] ) ) {
			return;
		}

		$post_id  = (int) $package_data['post_id'];
		$post_obj = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post_obj || ! $this->parser->has_etch_blocks( $post_obj ) ) {
			return;
		}

		// Use our own package kind — isolated from WPML's Gutenberg handler.
		$etch_package = array(
			'kind'    => self::PACKAGE_KIND,
			'name'    => $post_id,
			'title'   => 'Etch Page ' . $post_id,
			'post_id' => $post_id,
		);

		$values = $this->parser->get_translatable_values( $post_id );

		Logger::debug( 'Registering post strings', array(
			'post_id' => $post_id,
			'count'   => count( $values ),
			'values'  => $values,
		) );

		foreach ( $values as $value ) {
			do_action( 'wpml_register_string', $value, md5( $value ), $etch_package, $value, 'LINE' );
		}

		// No cleanup here — registration only. Stale string cleanup is handled
		// by MetaSync::process_post() at shutdown.
	}

	/** Remove package strings whose values are not in the current translatable set. */
	public function cleanup_stale_package_strings( int $post_id, array $current_values ): void {
		if ( ! self::is_string_translation_active() ) {
			return;
		}

		global $wpdb;

		$pkg_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->prefix}icl_string_packages WHERE post_id = %d AND kind = 'Etch'",
			$post_id
		) );

		if ( ! $pkg_id ) {
			return;
		}

		$all_strings = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, value FROM {$wpdb->prefix}icl_strings WHERE string_package_id = %d",
			$pkg_id
		) );

		$stale_ids = array();
		foreach ( $all_strings as $s ) {
			if ( ! in_array( $s->value, $current_values, true ) ) {
				$stale_ids[] = (int) $s->id;
			}
		}

		if ( empty( $stale_ids ) ) {
			Logger::debug( 'Stale package cleanup', array(
				'post_id' => $post_id,
				'removed' => 0,
			) );
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $stale_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_string_translations WHERE string_id IN ({$placeholders})", ...$stale_ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_strings WHERE id IN ({$placeholders})", ...$stale_ids ) );

		$field_types    = array_map( fn( $sid ) => "package-string-{$pkg_id}-{$sid}", $stale_ids );
		$ft_placeholders = implode( ',', array_fill( 0, count( $field_types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_translate WHERE field_type IN ({$ft_placeholders})", ...$field_types ) );

		Logger::debug( 'Cleaned stale package strings', array(
			'post_id'      => $post_id,
			'removed'      => count( $stale_ids ),
		) );
	}

	/**
	 * Remove all WPML Gutenberg package strings for a post.
	 *
	 * Called when a post's content is emptied so that stale strings from previous
	 * saves don't appear in translation jobs.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function remove_all_package_strings( int $post_id ): void {
		if ( ! self::is_string_translation_active() ) {
			return;
		}

		global $wpdb;

		$pkg_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->prefix}icl_string_packages WHERE post_id = %d AND kind = 'Etch'",
			$post_id
		) );

		if ( ! $pkg_id ) {
			return;
		}

		$string_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}icl_strings WHERE string_package_id = %d",
			$pkg_id
		) );

		if ( ! empty( $string_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from count.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_string_translations WHERE string_id IN ({$placeholders})", ...array_map( 'intval', $string_ids ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_strings WHERE id IN ({$placeholders})", ...array_map( 'intval', $string_ids ) ) );

			$field_types     = array_map( fn( $sid ) => "package-string-{$pkg_id}-{$sid}", $string_ids );
			$ft_placeholders = implode( ',', array_fill( 0, count( $field_types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_translate WHERE field_type IN ({$ft_placeholders})", ...$field_types ) );
		}

		// Remove the package itself.
		$wpdb->delete( $wpdb->prefix . 'icl_string_packages', array( 'ID' => $pkg_id ), array( '%d' ) );

		Logger::info( 'Removed all Etch package strings for empty post', array(
			'post_id'      => $post_id,
			'package_id'   => $pkg_id,
			'string_count' => count( $string_ids ),
		) );
	}

	/** Remove WPML strings for values that existed in the previous snapshot but not the current one. */
	public function cleanup_old_component_strings( int $post_id, array $previous_values, array $current_values ): void {
		if ( ! self::is_string_translation_active() ) {
			return;
		}

		$strings_to_delete = array_diff( $previous_values, $current_values );

		if ( empty( $strings_to_delete ) ) {
			return;
		}

		Logger::info( 'Cleaning up old component strings', array(
			'post_id'      => $post_id,
			'string_count' => count( $strings_to_delete ),
		) );

		global $wpdb;

		$context        = 'etch-' . $post_id;
		$all_string_ids = array();

		foreach ( $strings_to_delete as $string_value ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}icl_strings
				 WHERE value = %s
				 AND context = %s",
				$string_value,
				$context
			) );

			foreach ( $ids as $id ) {
				$all_string_ids[] = (int) $id;
			}
		}

		if ( ! empty( $all_string_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $all_string_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from count.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_string_translations WHERE string_id IN ({$placeholders})", ...$all_string_ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_strings WHERE id IN ({$placeholders})", ...$all_string_ids ) );
		}
	}
}
