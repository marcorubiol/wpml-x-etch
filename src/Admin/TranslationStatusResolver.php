<?php
/**
 * Single point of truth for translation status.
 *
 * Trusts WPML's icl_translation_status directly. The self-managed
 * registration (kind='Etch') and the needs_update loop fix
 * (wpml_pro_translation_completed hook in MetaSync) guarantee
 * that WPML's status is reliable.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Admin;

class TranslationStatusResolver {

	private readonly TranslationDataQuery $data_query;
	private readonly TranslationJobManager $job_manager;

	public function __construct( TranslationDataQuery $data_query, TranslationJobManager $job_manager ) {
		$this->data_query  = $data_query;
		$this->job_manager = $job_manager;
	}

	/** @return array<string, array> Keyed by language code. Empty if no WPML group. */
	public function resolve_post_lang_data( int $original_id, string $post_type, array $active_langs ): array {
		$trid = (int) apply_filters( 'wpml_element_trid', null, $original_id, 'post_' . $post_type );
		if ( ! $trid ) {
			return array();
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type );

		$maps          = $this->data_query->get_translation_status_maps( (int) $trid );
		$tid_by_lang   = $maps['tid_by_lang'];
		$status_by_tid = $maps['status_by_tid'];

		$lang_data = $this->data_query->build_lang_data( $active_langs, $translations, $tid_by_lang, $status_by_tid );

		return $lang_data;
	}

	/** @return array<int, array> Map of post_id => lang_data. */
	public function resolve_batch_lang_data( array $post_trids, string $element_type, array $active_langs ): array {
		if ( empty( $post_trids ) ) {
			return array();
		}

		$batch_maps = $this->data_query->get_batch_translation_status_maps( array_values( $post_trids ) );

		$result = array();

		foreach ( $post_trids as $post_id => $trid ) {
			$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );

			$maps          = $batch_maps[ $trid ] ?? array( 'tid_by_lang' => array(), 'status_by_tid' => array() );
			$tid_by_lang   = $maps['tid_by_lang'];
			$status_by_tid = $maps['status_by_tid'];

			$lang_data = $this->data_query->build_lang_data( $active_langs, $translations, $tid_by_lang, $status_by_tid );

			$result[ $post_id ] = $lang_data;
		}

		return $result;
	}

	/**
	 * Pill badges: worst status per content-type.
	 * Only considers originals in the default language (consistent with
	 * handle_get_posts_by_type).
	 *
	 * @return array<string, string> post_type => status.
	 */
	public function resolve_pill_statuses( array $post_types, array $active_langs ): array {
		if ( empty( $post_types ) ) {
			return array();
		}

		global $wpdb;

		$default_lang = apply_filters( 'wpml_default_language', null );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_type, t_orig.element_id, t_orig.trid, t_orig.language_code as original_lang,
			        t_trans.language_code,
			        s.status, s.needs_update
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->prefix}icl_translations t_orig
			   ON t_orig.element_id = p.ID
			   AND t_orig.element_type = CONCAT('post_', p.post_type)
			 LEFT JOIN {$wpdb->prefix}icl_translations t_trans
			   ON t_orig.trid = t_trans.trid
			   AND t_trans.source_language_code IS NOT NULL
			 LEFT JOIN {$wpdb->prefix}icl_translation_status s
			   ON t_trans.translation_id = s.translation_id
			 WHERE p.post_type IN ($placeholders)
			   AND p.post_status = 'publish'
			   AND t_orig.source_language_code IS NULL
			   AND t_orig.language_code = %s
			   AND (p.post_type != 'wp_template' OR (p.post_content LIKE '%%<!-- wp:etch/%%' AND p.post_name != 'home'))",
			...array_merge( $post_types, array( $default_lang ) )
		) );

		// Group by post_type → element_id.
		$pt_items = array();
		foreach ( $results as $r ) {
			$pt   = $r->post_type;
			$eid  = (int) $r->element_id;
			$lang = $r->language_code;

			if ( ! isset( $pt_items[ $pt ] ) ) {
				$pt_items[ $pt ] = array();
			}
			if ( ! isset( $pt_items[ $pt ][ $eid ] ) ) {
				$pt_items[ $pt ][ $eid ] = array(
					'original_lang' => $r->original_lang,
					'trid'          => (int) $r->trid,
					'langs'         => array(),
				);
			}

			if ( $lang && isset( $active_langs[ $lang ] ) ) {
				$pt_items[ $pt ][ $eid ]['langs'][ $lang ] = TranslationDataQuery::resolve_wpml_status( $r );
			}
		}

		$pill_statuses = array();

		foreach ( $pt_items as $pt => $items ) {
			$pt_all_lang_statuses = array();
			foreach ( $items as $item_data ) {
				foreach ( $active_langs as $code => $lang_details ) {
					if ( $code === $item_data['original_lang'] ) {
						continue;
					}
					$pt_all_lang_statuses[] = $item_data['langs'][ $code ] ?? 'not_translated';
				}
			}

			if ( empty( $pt_all_lang_statuses ) ) {
				$pill_statuses[ $pt ] = 'empty';
			} else {
				$worst = TranslationDataQuery::worst_status( $pt_all_lang_statuses );
				$pill_statuses[ $pt ] = 'translated' === $worst ? 'complete' : $worst;
			}
		}

		return $pill_statuses;
	}
}
