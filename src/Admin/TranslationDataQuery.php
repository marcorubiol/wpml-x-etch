<?php
/**
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Admin;

use WpmlXEtch\Etch\ComponentParser;
use WpmlXEtch\Utils\Logger;

/**
 * Fetches and builds WPML translation status data.
 */
class TranslationDataQuery {

	private readonly ComponentParser $parser;

	public function __construct( ComponentParser $parser ) {
		$this->parser = $parser;
	}

	public const ICL_TM_WAITING = 1;
	public const ICL_TM_IN_PROGRESS = 2;
	public const ICL_TM_COMPLETE = 10;

	/** Lower value = worse status. Higher = better. */
	public const STATUS_PRIORITY = array(
		'not_translated'   => 0,
		'waiting'          => 1,
		'needs_update'     => 2,
		'in_progress'      => 3,
		'translated'       => 4,
		'not_translatable' => 5,
	);

	/**
	 * Fetch translation ID and status maps for a given trid.
	 *
	 * @return array{tid_by_lang: array<string, int>, status_by_tid: array<int, object>}
	 */
	public function get_translation_status_maps( int $trid ): array {
		global $wpdb;

		$tid_by_lang = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT language_code, translation_id FROM {$wpdb->prefix}icl_translations WHERE trid = %d",
				$trid
			)
		);
		foreach ( (array) $rows as $row ) {
			$tid_by_lang[ $row->language_code ] = (int) $row->translation_id;
		}

		$status_by_tid = array();
		$t_ids         = array_values( $tid_by_lang );
		if ( $t_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $t_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$status_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT translation_id, status, needs_update FROM {$wpdb->prefix}icl_translation_status WHERE translation_id IN ({$placeholders})",
					...$t_ids
				)
			);
			foreach ( (array) $status_rows as $row ) {
				$status_by_tid[ (int) $row->translation_id ] = $row;
			}
		}

		return array(
			'tid_by_lang'   => $tid_by_lang,
			'status_by_tid' => $status_by_tid,
		);
	}

	/**
	 * Batch-fetch translation status maps for multiple trids in 2 queries total.
	 *
	 * @param int[] $trids Array of trid values.
	 * @return array<int, array{tid_by_lang: array<string, int>, status_by_tid: array<int, object>}> Keyed by trid.
	 */
	public function get_batch_translation_status_maps( array $trids ): array {
		if ( empty( $trids ) ) {
			return array();
		}

		global $wpdb;

		$trids        = array_map( 'intval', $trids );
		$placeholders = implode( ',', array_fill( 0, count( $trids ), '%d' ) );

		// Query 1: All translation IDs for all trids.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT trid, language_code, translation_id FROM {$wpdb->prefix}icl_translations WHERE trid IN ({$placeholders})",
				...$trids
			)
		);

		$maps    = array();
		$all_tids = array();
		foreach ( (array) $rows as $row ) {
			$t = (int) $row->trid;
			if ( ! isset( $maps[ $t ] ) ) {
				$maps[ $t ] = array( 'tid_by_lang' => array(), 'status_by_tid' => array() );
			}
			$tid                                = (int) $row->translation_id;
			$maps[ $t ]['tid_by_lang'][ $row->language_code ] = $tid;
			$all_tids[]                         = $tid;
		}

		// Query 2: All statuses for all translation IDs.
		if ( $all_tids ) {
			$tid_placeholders = implode( ',', array_fill( 0, count( $all_tids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$status_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT translation_id, status, needs_update FROM {$wpdb->prefix}icl_translation_status WHERE translation_id IN ({$tid_placeholders})",
					...$all_tids
				)
			);

			// Build a tid → trid reverse lookup.
			$tid_to_trid = array();
			foreach ( $maps as $t => $map ) {
				foreach ( $map['tid_by_lang'] as $tid ) {
					$tid_to_trid[ $tid ] = $t;
				}
			}

			foreach ( (array) $status_rows as $row ) {
				$tid  = (int) $row->translation_id;
				$trid = $tid_to_trid[ $tid ] ?? 0;
				if ( $trid ) {
					$maps[ $trid ]['status_by_tid'][ $tid ] = $row;
				}
			}
		}

		// Ensure every requested trid has an entry.
		foreach ( $trids as $t ) {
			if ( ! isset( $maps[ $t ] ) ) {
				$maps[ $t ] = array( 'tid_by_lang' => array(), 'status_by_tid' => array() );
			}
		}

		return $maps;
	}

	/**
	 * Merge active languages, WPML translations, and status rows into a
	 * flat per-language array suitable for the frontend.
	 */
	public function build_lang_data( array $active_langs, mixed $translations, array $translation_ids_by_lang, array $status_by_tid ): array {
		$lang_data = array();

		foreach ( $active_langs as $code => $lang ) {
			$translation   = $translations[ $code ] ?? null;
			$translated_id = $translation ? (int) $translation->element_id : null;
			$is_original   = $translation && (int) $translation->original === 1;

			$tid        = $translation_ids_by_lang[ $code ] ?? 0;
			$status_row = $tid ? $status_by_tid[ $tid ] ?? null : null;
			$status     = self::resolve_wpml_status( $status_row );

			// Fallback: translated WP post exists but WPML has no status row at all.
			// Does NOT apply to 'waiting' — that has a row, just hasn't been translated yet.
			if ( 'not_translated' === $status && $translated_id ) {
				$status = 'translated';
			}

			// Ghost-row guard (symmetric to the fallback above): WPML says translated
			// or needs_update but no translated post exists. Orphan status rows can
			// come from translation attempts that predated this plugin or from
			// aborted jobs. Trusting them surfaces false-positive "Complete" on pages
			// that have no real translation. Aligns with WPML's Pages-list view.
			// 'waiting' and 'in_progress' are left alone — they can legitimately
			// exist before the translated post is created.
			if ( ! $is_original && ! $translated_id
				&& ( 'translated' === $status || 'needs_update' === $status ) ) {
				$status = 'not_translated';
			}

			$lang_data[ $code ] = array(
				'code'        => $code,
				'native_name' => $lang['native_name'],
				'flag_url'    => $lang['country_flag_url'],
				'is_original' => $is_original,
				'status'      => $status,
			);
		}

		return $lang_data;
	}

	/**
	 * Worst status for each language considering both page and its components.
	 * Priority: not_translated > needs_update > in_progress > translated.
	 */
	public function calculate_combined_status( array $page_languages, array $components, array $loop_statuses = array() ): array {
		$combined = array();

		foreach ( $page_languages as $code => $lang ) {
			$statuses = array( $lang['status'] );

			foreach ( $components as $component ) {
				$component_lang = $component['languages'][ $code ] ?? null;
				if ( ! $component_lang || $component_lang['is_original'] ) {
					continue;
				}
				$statuses[] = $component_lang['status'];
			}

			foreach ( $loop_statuses as $loop_status ) {
				if ( isset( $loop_status[ $code ] ) ) {
					$statuses[] = $loop_status[ $code ];
				}
			}

			$combined[ $code ] = array(
				'code'        => $lang['code'],
				'native_name' => $lang['native_name'],
				'flag_url'    => $lang['flag_url'],
				'is_original' => $lang['is_original'],
				'status'      => self::worst_status( $statuses ),
			);
		}

		return $combined;
	}

	/**
	 * Get translation status for JSON loop strings per language.
	 *
	 * @param array $loop_names Loop display names (used as string name prefixes).
	 * @param array $lang_codes Target language codes.
	 * @return array { 'Basic Nav' => { 'es' => 'translated', 'ca' => 'not_translated' }, ... }
	 */
	public function get_loop_string_statuses( array $loop_names, array $lang_codes ): array {
		if ( empty( $loop_names ) || empty( $lang_codes ) ) {
			return array();
		}

		global $wpdb;

		// Build LIKE conditions for all loop names.
		$like_conditions = array();
		$like_values     = array();
		foreach ( $loop_names as $loop_name ) {
			$like_conditions[] = 's.name LIKE %s';
			$like_values[]     = $wpdb->esc_like( $loop_name ) . ' ›%';
		}
		$like_sql = '(' . implode( ' OR ', $like_conditions ) . ')';

		// Query 1: Total string count per loop (language-independent).
		$total_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT SUBSTRING_INDEX(s.name, ' ›', 1) AS loop_name, COUNT(*) AS total
			 FROM {$wpdb->prefix}icl_strings s
			 WHERE s.context = 'Etch JSON Loops' AND {$like_sql}
			 GROUP BY loop_name",
			...$like_values
		) );

		$totals = array();
		foreach ( $total_rows as $row ) {
			$totals[ $row->loop_name ] = (int) $row->total;
		}

		if ( empty( $totals ) ) {
			return array();
		}

		// Query 2: Translated count per loop + language.
		$lang_placeholders = implode( ',', array_fill( 0, count( $lang_codes ), '%s' ) );

		$trans_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT SUBSTRING_INDEX(s.name, ' ›', 1) AS loop_name,
			        st.language AS lang_code,
			        COUNT(CASE WHEN st.status = 10 THEN 1 END) AS translated
			 FROM {$wpdb->prefix}icl_strings s
			 INNER JOIN {$wpdb->prefix}icl_string_translations st
			     ON s.id = st.string_id
			 WHERE s.context = 'Etch JSON Loops'
			   AND {$like_sql}
			   AND st.language IN ({$lang_placeholders})
			 GROUP BY loop_name, st.language",
			...array_merge( $like_values, $lang_codes )
		) );

		$translated_counts = array();
		foreach ( $trans_rows as $row ) {
			$translated_counts[ $row->loop_name ][ $row->lang_code ] = (int) $row->translated;
		}

		// Build result: compare translated vs total per loop per language.
		$result = array();
		foreach ( $loop_names as $loop_name ) {
			$total = $totals[ $loop_name ] ?? 0;
			if ( $total === 0 ) {
				continue;
			}
			foreach ( $lang_codes as $lang_code ) {
				$translated = $translated_counts[ $loop_name ][ $lang_code ] ?? 0;
				$result[ $loop_name ][ $lang_code ] = ( $translated >= $total ) ? 'translated' : 'not_translated';
			}
		}

		return $result;
	}

	/** Find etch/component refs in a page and return each with per-language status. */
	public function get_components_in_page( int $post_id, array $active_langs ): array {
		// Ensure we read the original post content, not a WPML-filtered translation.
		$post = get_post( (int) apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), true, apply_filters( 'wpml_default_language', null ) ) );
		if ( ! $post || empty( $post->post_content ) ) {
			return array();
		}

		$blocks        = parse_blocks( $post->post_content );
		$component_ids = $this->extract_component_refs( $blocks );

		if ( empty( $component_ids ) ) {
			return array();
		}

		Logger::debug( 'Found components in page', array(
			'post_id'         => $post_id,
			'component_ids'   => $component_ids,
			'component_count' => count( $component_ids ),
		) );

		$components = array();

		// Batch-fetch all component posts. Suppress WPML filters to avoid
		// fetching translated duplicates alongside originals.
		$component_posts = get_posts( array(
			'include'          => array_values( $component_ids ),
			'post_type'        => 'wp_block',
			'post_status'      => 'publish',
			'posts_per_page'   => count( $component_ids ),
			'suppress_filters' => true,
		) );
		$posts_by_id = array();
		foreach ( $component_posts as $cp ) {
			$posts_by_id[ $cp->ID ] = $cp;
		}

		// Collect trids, then batch-fetch translation status maps.
		$comp_trids = array();
		foreach ( $component_ids as $component_id ) {
			if ( ! isset( $posts_by_id[ $component_id ] ) ) {
				continue;
			}
			$trid = (int) apply_filters( 'wpml_element_trid', null, $component_id, 'post_wp_block' );
			if ( $trid ) {
				$comp_trids[ $component_id ] = (int) $trid;
			}
		}

		$batch_maps = $this->get_batch_translation_status_maps( array_values( $comp_trids ) );

		foreach ( $component_ids as $component_id ) {
			$component_post = $posts_by_id[ $component_id ] ?? null;
			$trid           = $comp_trids[ $component_id ] ?? 0;
			if ( ! $component_post || ! $trid ) {
				continue;
			}

			$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_wp_block' );

			$maps                    = $batch_maps[ $trid ] ?? array( 'tid_by_lang' => array(), 'status_by_tid' => array() );
			$translation_ids_by_lang = $maps['tid_by_lang'];
			$status_by_tid           = $maps['status_by_tid'];

			$lang_status = $this->build_lang_data( $active_langs, $translations, $translation_ids_by_lang, $status_by_tid );

			$components[] = array(
				'id'        => $component_id,
				'title'     => $component_post->post_title,
				'languages' => $lang_status,
			);
		}

		return $components;
	}

	public function extract_component_refs( array $blocks ): array {
		return $this->parser->extract_component_refs( $blocks );
	}

	public static function resolve_wpml_status( ?object $status_row ): string {
		if ( ! $status_row ) {
			return 'not_translated';
		}

		// In-progress (status=2) takes priority over needs_update.
		if ( (int) $status_row->status === self::ICL_TM_IN_PROGRESS ) {
			return 'in_progress';
		}
		if ( (int) $status_row->needs_update === 1 ) {
			return 'needs_update';
		}
		if ( (int) $status_row->status === self::ICL_TM_COMPLETE ) {
			return 'translated';
		}
		// Waiting (status=1) = job assigned but no one started translating.
		// WPML shows "Needs translation". We show "not_translated" but mark it
		// distinctly so build_lang_data's fallback doesn't override it.
		if ( (int) $status_row->status === self::ICL_TM_WAITING ) {
			return 'waiting';
		}

		return 'not_translated';
	}

	public static function worst_status( array $statuses ): string {
		if ( empty( $statuses ) ) {
			return 'not_translated';
		}

		$worst          = 'translated';
		$worst_priority = self::STATUS_PRIORITY[ $worst ];

		foreach ( $statuses as $status ) {
			$priority = self::STATUS_PRIORITY[ $status ] ?? 3;
			if ( $priority < $worst_priority ) {
				$worst          = $status;
				$worst_priority = $priority;
			}
		}

		return $worst;
	}
}
