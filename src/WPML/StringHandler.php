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

		// No cleanup here — registration only. Stale string cleanup is handled
		// by MetaSync::process_post() at shutdown.
		$this->register_package_strings( $post_id );
	}

	/**
	 * Whether visitors keep the previous translation of an edited text until
	 * it is translated again. Filter to false for the pre-1.2.9 behaviour: the
	 * source text shows as soon as the original changes.
	 */
	public static function keeps_previous_translation(): bool {
		return (bool) apply_filters( 'zs_wxe_keep_previous_translation', true );
	}

	/**
	 * Register a post's Etch strings in its own package, in document order.
	 *
	 * A string's identity is md5 of its value, so editing a text registers a
	 * new string and leaves the old one stale until cleanup deletes it with
	 * its translations. Before that happens, WPML's own page-builder reuse
	 * pass — the one Gutenberg, Elementor and Beaver Builder run — pairs each
	 * new string with a removed one (same location and similar text, or
	 * similar text alone) and copies the translations over with status
	 * "needs update". The previous wording keeps rendering while the page
	 * still reads as needing an update.
	 */
	public function register_package_strings( int $post_id ): void {
		if ( ! self::is_string_translation_active() ) {
			return;
		}

		// Use our own package kind — isolated from WPML's Gutenberg handler.
		$package = array(
			'kind'    => self::PACKAGE_KIND,
			'name'    => $post_id,
			'title'   => 'Etch Page ' . $post_id,
			'post_id' => $post_id,
		);

		$values = $this->parser->get_translatable_values_in_order( $post_id );

		Logger::debug( 'Registering post strings', array(
			'post_id' => $post_id,
			'count'   => count( $values ),
			'values'  => $values,
		) );

		$before = $this->get_package_strings( $post_id );

		foreach ( $values as $value ) {
			do_action( 'wpml_register_string', $value, md5( $value ), $package, $value, 'LINE' );
		}

		$after = $this->get_package_strings( $post_id );
		$after = $this->update_string_locations( $after, array_values( array_unique( $values ) ) );

		$this->reuse_translations( $post_id, $before, $after, $values );
	}

	/**
	 * Strings in a post's Etch package, in the shape WPML's reuse pass reads.
	 *
	 * @return array<int, array{id: int, value: string, location: int}>
	 */
	private function get_package_strings( int $post_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.value, s.location
			 FROM {$wpdb->prefix}icl_strings s
			 JOIN {$wpdb->prefix}icl_string_packages p ON s.string_package_id = p.ID
			 WHERE p.kind = %s AND p.post_id = %d",
			self::PACKAGE_KIND,
			$post_id
		) );

		$strings = array();
		foreach ( (array) $rows as $row ) {
			$strings[ (int) $row->id ] = array(
				'id'       => (int) $row->id,
				'value'    => (string) $row->value,
				'location' => (int) $row->location,
			);
		}

		return $strings;
	}

	/**
	 * Store each current string's position (1-based, document order) as its
	 * WPML location. Stale strings keep the location of their last save.
	 *
	 * @param array<int, array{id: int, value: string, location: int}> $strings
	 * @param string[]                                                 $ordered Unique values in document order.
	 * @return array<int, array{id: int, value: string, location: int}>
	 */
	private function update_string_locations( array $strings, array $ordered ): array {
		global $wpdb;

		$positions = array_flip( $ordered );
		foreach ( $strings as $id => $string ) {
			if ( ! isset( $positions[ $string['value'] ] ) ) {
				continue;
			}
			$location = $positions[ $string['value'] ] + 1;
			if ( $location !== $string['location'] ) {
				$wpdb->update( $wpdb->prefix . 'icl_strings', array( 'location' => $location ), array( 'id' => $id ) );
				$strings[ $id ]['location'] = $location;
			}
		}

		return $strings;
	}

	/**
	 * Minimum character similarity (similar_text) for our own same-location
	 * reuse pass. Catches short-label edits WPML's word-based diff misses
	 * ("Jetzt bewerben" → "Jetzt bewerben!" is 97% here, 36% for WPML) while
	 * real rewrites ("Über uns" → "Unser Team", 32%) stay pending.
	 */
	private const REUSE_MIN_CHAR_SIMILARITY = 60;

	/**
	 * Whether a value is a link target (path, anchor, URL, mailto/tel) rather
	 * than visible text.
	 */
	public static function is_url_like( string $value ): bool {
		return 1 === preg_match( '~^(?:https?://|mailto:|tel:|/|#|\?)\S*$~i', trim( $value ) );
	}

	/**
	 * Carry translations from removed strings over to the strings that
	 * replaced them.
	 *
	 * First WPML's own reuse pass, then ours for what it left unpaired: a new
	 * string takes over the removed string at the same location when both are
	 * link targets (paths are never "similar" to each other) or when their
	 * characters are at least REUSE_MIN_CHAR_SIMILARITY percent alike.
	 *
	 * @param array<int, array{id: int, value: string, location: int}> $before Package strings before registration.
	 * @param array<int, array{id: int, value: string, location: int}> $after  Package strings after registration.
	 * @param string[]                                                 $values Current translatable values.
	 */
	private function reuse_translations( int $post_id, array $before, array $after, array $values ): void {
		$current   = array_flip( $values );
		$new       = array_diff_key( $after, $before );
		$leftovers = array_filter( $before, fn( array $s ): bool => ! isset( $current[ $s['value'] ] ) );

		if ( ! $new || ! $leftovers || ! self::keeps_previous_translation() ) {
			return;
		}

		// Internal WPML classes: skip reuse (texts fall back to "pending") if a
		// future WPML renames them rather than failing the save.
		if ( ! class_exists( '\WPML_PB_Reuse_Translations' ) || ! class_exists( '\WPML_ST_String_Factory' ) ) {
			Logger::warning( 'WPML translation reuse unavailable', array( 'post_id' => $post_id ) );
			return;
		}

		global $wpdb;
		$factory = new \WPML_ST_String_Factory( $wpdb );
		$reused  = 0;

		try {
			( new \WPML_PB_Reuse_Translations( $factory ) )->find_and_reuse_translations( $before, $after, $leftovers );

			$by_location = array();
			foreach ( $leftovers as $leftover ) {
				$by_location[ $leftover['location'] ][] = $leftover;
			}

			foreach ( $new as $string ) {
				if ( ! isset( $by_location[ $string['location'] ] ) || $factory->find_by_id( $string['id'] )->get_translations() ) {
					continue; // Nothing at that location, or WPML's pass already paired it.
				}
				foreach ( $by_location[ $string['location'] ] as $leftover ) {
					if ( $this->is_same_text_edited( $leftover['value'], $string['value'] ) ) {
						$this->copy_translations( $factory, $leftover['id'], $string['id'] );
						$reused++;
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			Logger::warning( 'WPML translation reuse failed', array(
				'post_id' => $post_id,
				'error'   => $e->getMessage(),
			) );
			return;
		}

		Logger::debug( 'Reused translations for edited strings', array(
			'post_id'            => $post_id,
			'new'                => count( $new ),
			'leftovers'          => count( $leftovers ),
			'same_location_pass' => $reused,
		) );
	}

	/** Whether $new is an edit of $old at the same location, per our own pass. */
	private function is_same_text_edited( string $old, string $new ): bool {
		if ( self::is_url_like( $old ) || self::is_url_like( $new ) ) {
			return self::is_url_like( $old ) && self::is_url_like( $new );
		}
		similar_text( $old, $new, $percent );

		return $percent >= self::REUSE_MIN_CHAR_SIMILARITY;
	}

	/**
	 * Copy a string's translations to another string, completed ones demoted
	 * to "needs update" — the same rule as WPML_PB_Reuse_Translations.
	 */
	private function copy_translations( \WPML_ST_String_Factory $factory, int $from_id, int $to_id ): void {
		$to = $factory->find_by_id( $to_id );
		foreach ( $factory->find_by_id( $from_id )->get_translations() as $translation ) {
			if ( null === $translation->value || '' === $translation->value ) {
				continue;
			}
			$status = (int) $translation->status === ICL_TM_COMPLETE ? ICL_TM_NEEDS_UPDATE : (int) $translation->status;
			$to->set_translation(
				$translation->language,
				$translation->value,
				$status,
				$translation->translator_id,
				$translation->translation_service,
				$translation->batch_id
			);
		}
	}

	/**
	 * Translations of a post's Etch strings for one language.
	 *
	 * Completed translations win. A string without one falls back to its
	 * "needs update" translation — the previous wording carried over by
	 * reuse — unless keeps_previous_translation() is off. Strings left with
	 * neither are counted as pending, except values that are not visible text:
	 * WPML's non-translatable values and link targets, which render as they
	 * are in the original rather than holding the whole translated page.
	 *
	 * @return array{translations: array<string, string>, pending: int} Map of original => translated.
	 */
	public static function get_package_translations( int $post_id, string $lang ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.value AS original, st.value AS translated, st.status
			 FROM {$wpdb->prefix}icl_strings s
			 JOIN {$wpdb->prefix}icl_string_packages p ON s.string_package_id = p.ID
			 LEFT JOIN {$wpdb->prefix}icl_string_translations st
			     ON st.string_id = s.id AND st.language = %s AND st.status IN (10, 3)
			 WHERE p.kind = %s AND p.post_id = %d",
			$lang,
			self::PACKAGE_KIND,
			$post_id
		) );

		$keep_previous = self::keeps_previous_translation();
		$translations  = array();
		$pending       = 0;

		foreach ( (array) $rows as $row ) {
			$usable = 10 === (int) $row->status
				|| ( $keep_previous && 3 === (int) $row->status && null !== $row->translated && '' !== $row->translated );

			if ( ! $usable ) {
				if ( ! self::is_not_translatable( (string) $row->original ) && ! self::is_url_like( (string) $row->original ) ) {
					$pending++;
				}
				continue;
			}

			if ( $row->original !== $row->translated ) {
				$translations[ $row->original ] = (string) $row->translated;
			}
		}

		return array(
			'translations' => $translations,
			'pending'      => $pending,
		);
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
