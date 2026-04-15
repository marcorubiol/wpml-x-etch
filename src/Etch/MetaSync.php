<?php
/**
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Etch;

use WpmlXEtch\Core\SubscriberInterface;
use WpmlXEtch\WPML\StringHandler;
use WpmlXEtch\WPML\TranslationSync;
use WpmlXEtch\Utils\Logger;

/**
 * Syncs Etch meta across WPML translations on save_post / shutdown.
 *
 * Handles: value snapshots, string cleanup, component refs, meta copy,
 * and component change propagation. Does NOT set needs_update — WPML's
 * own deferred mechanism handles that. See ARCHITECTURE.md.
 */
class MetaSync implements SubscriberInterface {

	private readonly ComponentParser $parser;
	private readonly StringHandler $string_handler;
	private readonly TranslationSync $translation_sync;
	private array $save_queue = array();

	/** @var array<int, array{original_id: int, translated_id: int}> */
	private array $ate_completions = array();

	public function __construct( ComponentParser $parser, StringHandler $string_handler, TranslationSync $translation_sync ) {
		$this->parser           = $parser;
		$this->string_handler   = $string_handler;
		$this->translation_sync = $translation_sync;
	}

	public static function getSubscribedEvents(): array {
		return array(
			array( 'save_post', 'queue_post_for_sync', 10, 2 ),
			array( 'shutdown', 'process_save_queue', PHP_INT_MAX ),
			array( 'updated_post_meta', 'queue_component_meta_sync', 20, 4 ),
			array( 'added_post_meta', 'queue_component_meta_sync', 20, 4 ),
			array( 'wpml_tm_post_md5_content', 'filter_md5_content', 20, 2 ),
			array( 'wpml_pro_translation_completed', 'record_ate_completion', 10, 3 ),
			array( 'shutdown', 'fix_needs_update_after_ate', 12 ),
		);
	}

	/** Hook: save_post — queue original posts that contain (or previously contained) Etch blocks. */
	public function queue_post_for_sync( int $post_id, \WP_Post|null $post = null ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! ( $post instanceof \WP_Post ) ) {
			$post = get_post( $post_id );
		}
		if ( ! $post ) {
			return;
		}

		// Queue if post currently has Etch blocks, OR if it previously had them
		// (detected by the values snapshot). This handles the "editor emptied" case
		// where content was removed but translations still need invalidation.
		$has_blocks    = $this->parser->has_etch_blocks( $post );
		$had_blocks    = ! empty( get_post_meta( $post_id, '_zs_wxe_values', true ) );

		if ( ! $has_blocks && ! $had_blocks ) {
			return;
		}
		$this->save_queue[ $post_id ] = true;
	}

	/** Hook: shutdown — process all queued posts in a single pass. */
	public function process_save_queue(): void {
		if ( empty( $this->save_queue ) ) {
			return;
		}

		Logger::debug( 'Processing meta sync queue', array(
			'post_count' => count( $this->save_queue ),
			'post_ids'   => array_keys( $this->save_queue ),
		) );

		while ( ! empty( $this->save_queue ) ) {
			$pid = array_key_first( $this->save_queue );
			unset( $this->save_queue[ $pid ] );
			$this->process_post( (int) $pid );
		}
	}

	/** Hook: updated_post_meta / added_post_meta — queue component when its properties change. */
	public function queue_component_meta_sync( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		static $queued = array();

		if ( 'etch_component_properties' !== $meta_key ) {
			return;
		}
		if ( isset( $queued[ $object_id ] ) ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return;
		}

		$lang_details = apply_filters( 'wpml_element_language_details', null, array(
			'element_id'   => $object_id,
			'element_type' => 'post_wp_block',
		) );

		if ( ! is_object( $lang_details ) || ! $this->is_original( $lang_details ) ) {
			return;
		}

		$queued[ $object_id ]           = true;
		$this->save_queue[ $object_id ] = true;
	}

	/** Record ATE completions — needs_update fix runs at shutdown (after WPML's save_translation). */
	public function record_ate_completion( int $translated_post_id, array $fields, object $job ): void {
		if ( ! isset( $job->original_doc_id ) ) {
			return;
		}
		$this->ate_completions[] = array(
			'original_id'   => (int) $job->original_doc_id,
			'translated_id' => $translated_post_id,
		);
	}

	/** Break WPML's needs_update self-reinforcing loop by verifying md5 after ATE completion. */
	public function fix_needs_update_after_ate(): void {
		if ( empty( $this->ate_completions ) || ! class_exists( '\WPML_TM_Action_Helper' ) ) {
			return;
		}

		global $wpdb;
		$helper = new \WPML_TM_Action_Helper();

		foreach ( $this->ate_completions as $completion ) {
			$original = get_post( $completion['original_id'] );
			if ( ! $original ) {
				continue;
			}

			$current_md5 = $helper->post_md5( $original );

			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT ts.translation_id, ts.md5, ts.needs_update, ts.status
				 FROM {$wpdb->prefix}icl_translation_status ts
				 JOIN {$wpdb->prefix}icl_translations t ON ts.translation_id = t.translation_id
				 WHERE t.element_id = %d AND t.source_language_code IS NOT NULL",
				$completion['translated_id']
			) );

			if ( ! $row || (int) $row->needs_update !== 1 || (int) $row->status !== 10 ) {
				continue;
			}

			if ( $current_md5 === $row->md5 || empty( $row->md5 ) ) {
				// Don't clear needs_update if there are untranslated Etch strings.
				$lang = $wpdb->get_var( $wpdb->prepare(
					"SELECT language_code FROM {$wpdb->prefix}icl_translations
					 WHERE element_id = %d AND source_language_code IS NOT NULL",
					$completion['translated_id']
				) );
				if ( $lang && $this->has_untranslated_etch_strings( $completion['original_id'], $lang ) ) {
					continue;
				}

				$update_data = array( 'needs_update' => 0 );
				if ( empty( $row->md5 ) ) {
					$update_data['md5'] = $current_md5;
				}
				$wpdb->update(
					$wpdb->prefix . 'icl_translation_status',
					$update_data,
					array( 'translation_id' => (int) $row->translation_id )
				);

				Logger::debug( 'Fixed stale needs_update after ATE completion', array(
					'original_id'    => $completion['original_id'],
					'translated_id'  => $completion['translated_id'],
					'translation_id' => (int) $row->translation_id,
					'md5'            => $current_md5,
				) );
			}
		}

		$this->ate_completions = array();
	}

	private function has_untranslated_etch_strings( int $original_post_id, string $lang ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total, COUNT(st.id) AS translated
			 FROM {$wpdb->prefix}icl_strings s
			 JOIN {$wpdb->prefix}icl_string_packages p ON s.string_package_id = p.ID
			 LEFT JOIN {$wpdb->prefix}icl_string_translations st
			     ON st.string_id = s.id AND st.language = %s AND st.status = 10
			 WHERE p.kind = %s AND p.post_id = %d",
			$lang,
			\WpmlXEtch\WPML\StringHandler::PACKAGE_KIND,
			$original_post_id
		) );
		return $row && (int) $row->total > 0 && (int) $row->translated < (int) $row->total;
	}

	/** Make WPML's change detection ignore structural-only edits. */
	public function filter_md5_content( string $content, \WP_Post $post ): string {
		if ( ! $this->parser->has_etch_blocks( $post ) ) {
			return $content;
		}
		return implode( '|', $this->parser->get_translatable_values( $post->ID ) );
	}

	private function process_post( int $post_id ): void {
		static $processing = array();
		if ( isset( $processing[ $post_id ] ) ) {
			return;
		}
		$processing[ $post_id ] = true;

		$post = get_post( $post_id );
		if ( ! $post ) {
			unset( $processing[ $post_id ] );
			return;
		}

		$post_type    = $post->post_type;
		$element_type = 'post_' . $post_type;

		$lang_details = apply_filters( 'wpml_element_language_details', null, array(
			'element_id'   => $post_id,
			'element_type' => $element_type,
		) );

		if ( ! is_object( $lang_details ) || ! $this->is_original( $lang_details ) ) {
			unset( $processing[ $post_id ] );
			return;
		}

		$trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
		if ( ! $trid ) {
			unset( $processing[ $post_id ] );
			return;
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
		if ( empty( $translations ) || ! is_array( $translations ) ) {
			unset( $processing[ $post_id ] );
			return;
		}

		// Snapshot translatable values for change detection on future saves.
		$values_key      = '_zs_wxe_values';
		$current_values  = $this->parser->get_translatable_values( $post_id );
		$previous_values = get_post_meta( $post_id, $values_key, true );
		$previous_values = is_array( $previous_values ) ? $previous_values : array();
		$values_changed  = $current_values !== $previous_values;

		Logger::debug( 'Processing post sync', array(
			'post_id'        => $post_id,
			'values_changed' => $values_changed,
			'current_count'  => count( $current_values ),
			'previous_count' => count( $previous_values ),
		) );

		$string_handler = $this->string_handler;

		// If content is empty, clean up the entire WPML string package.
		if ( empty( $current_values ) && ! empty( $previous_values ) ) {
			$string_handler->remove_all_package_strings( $post_id );
		} elseif ( ! empty( $current_values ) ) {
			// Clean up strings that no longer match current translatable values.
			// Registration happens via wpml_page_builder_register_strings (priority 20).
			$string_handler->cleanup_stale_package_strings( $post_id, $current_values );
		}

		// Persist current values snapshot.
		update_post_meta( $post_id, $values_key, $current_values );

		// Store component refs as meta for efficient reverse-lookup.
		if ( 'wp_block' !== $post_type ) {
			$blocks        = parse_blocks( $post->post_content );
			$component_ids = $this->parser->extract_component_refs( $blocks );
			$component_ids = array_filter( $component_ids, function ( int $cid ): bool {
				return get_post( $cid ) !== null;
			} );
			if ( ! empty( $component_ids ) ) {
				update_post_meta( $post_id, '_zs_wxe_component_refs', wp_json_encode( array_values( $component_ids ) ) );
			} else {
				delete_post_meta( $post_id, '_zs_wxe_component_refs' );
			}
		}

		// Re-queue every referenced component on each page save, regardless
		// of whether it already has a snapshot. Catches:
		//   - imported components that have never been saved directly
		//   - components whose post_content was changed out-of-band (CLI,
		//     programmatic, REST from outside the Etch UI flow) — see
		//     `project_component_save_propagation.md`
		// The strict per-post model is preserved: process_post(component)
		// marks needs_update on the component's own translations only, never
		// on dependent pages. Cost is bounded — one parse_blocks + diff per
		// component, real work happens only when values actually changed.
		if ( 'wp_block' !== $post_type && ! empty( $component_ids ) ) {
			foreach ( $component_ids as $cid ) {
				$this->save_queue[ (int) $cid ] = true;
			}
		}

		// For components: clean up removed strings only.
		// Strict per-post model: a component edit does NOT mark its dependent
		// pages as needs_update — each post's status reflects only its own
		// strings. The Current Context UI surfaces aggregated state via
		// per-section indicators, so users still see when something needs
		// attention without poisoning every page that uses the component.
		if ( 'wp_block' === $post_type ) {
			$string_handler->cleanup_old_component_strings( $post_id, $previous_values, $current_values );
		}

		// Mark translations as needs_update when translatable strings changed.
		// WPML's deferred mechanism only detects post_content md5 changes,
		// not changes in our self-managed Etch string package.
		if ( $values_changed && ! empty( $current_values ) ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}icl_translation_status s
				 JOIN {$wpdb->prefix}icl_translations t ON s.translation_id = t.translation_id
				 SET s.needs_update = 1
				 WHERE t.trid = %d AND t.source_language_code IS NOT NULL",
				$trid
			) );
			Logger::debug( 'Marked trid translations as needs_update', array(
				'post_id'       => $post_id,
				'trid'          => $trid,
				'rows_affected' => $wpdb->rows_affected,
			) );
		}

		// Copy Etch meta to all existing translated posts.
		foreach ( $translations as $translation ) {
			if ( ! empty( $translation->original ) ) {
				continue;
			}
			$translated_id = ! empty( $translation->element_id ) ? (int) $translation->element_id : 0;
			if ( $translated_id ) {
				$this->translation_sync->copy_etch_meta( $post_id, $translated_id );
			}
		}

		unset( $processing[ $post_id ] );
	}

	private function is_original( object $lang_details ): bool {
		if ( property_exists( $lang_details, 'original' ) && $lang_details->original ) {
			return true;
		}
		if ( property_exists( $lang_details, 'source_language_code' ) && null === $lang_details->source_language_code ) {
			return true;
		}
		return false;
	}
}
