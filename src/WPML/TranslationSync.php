<?php
/**
 * WPML translation synchronization handler.
 *
 * Queries icl_translation_status directly to set needs_update.
 * WPML has no public API for marking a single translation as stale.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\WPML;

use WpmlXEtch\Core\SubscriberInterface;
use WpmlXEtch\Utils\Logger;

/**
 * Handles synchronization of Etch meta between original and translated posts.
 *
 * Applying translations to post content is handled natively by WPML via
 * wpml-config.xml + the Advanced Translation Editor. This class only deals
 * with the parts WPML cannot handle on its own:
 * - Copying etch_* post meta to translated posts.
 * - Marking translation jobs as needing update when a component changes.
 */
class TranslationSync implements SubscriberInterface {

	public static function getSubscribedEvents(): array {
		return array(
			'wpml_translation_update' => 'sync_on_translation_update',
		);
	}

	/** Hook: wpml_translation_update — copy Etch meta to the newly translated post. */
	public function sync_on_translation_update( array $data ): void {
		if ( empty( $data['trid'] ) || empty( $data['element_id'] ) ) {
			return;
		}

		$element_type = $data['element_type'] ?? 'post_post';
		$translations = apply_filters( 'wpml_get_element_translations', null, $data['trid'], $element_type );

		if ( empty( $translations ) || ! is_array( $translations ) ) {
			return;
		}

		$original_id = null;
		foreach ( $translations as $translation ) {
			if ( ! empty( $translation->original ) ) {
				$original_id = (int) $translation->element_id;
				break;
			}
		}

		$translated_id = (int) $data['element_id'];

		if ( ! $original_id || $original_id === $translated_id ) {
			return;
		}

		Logger::info( 'Translation updated, syncing meta', array(
			'original_id'   => $original_id,
			'translated_id' => $translated_id,
		) );

		$this->copy_etch_meta( $original_id, $translated_id );
	}

	public function copy_etch_meta( int $from_id, int $to_id ): void {
		global $wpdb;

		$etch_like = "( meta_key LIKE 'etch_%%' OR meta_key LIKE '_etch_%%' )";

		// 1. Delete all existing etch meta on the target post.
		$result = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta}
			 WHERE post_id = %d AND {$etch_like}",
			$to_id
		) );
		if ( false === $result ) {
			Logger::warning( 'Failed to delete stale Etch meta', array(
				'to_id' => $to_id,
			) );
		}

		// 2. Copy all etch meta from source to target in one INSERT…SELECT.
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value )
			 SELECT %d, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE post_id = %d AND {$etch_like}",
			$to_id,
			$from_id
		) );
		if ( false === $result ) {
			Logger::warning( 'Failed to copy Etch meta', array(
				'from_id' => $from_id,
				'to_id'   => $to_id,
			) );
		}

		// 3. Invalidate WP object cache for the target post's meta.
		wp_cache_delete( $to_id, 'post_meta' );

		Logger::debug( 'Copied Etch meta (batch)', array(
			'from_id' => $from_id,
			'to_id'   => $to_id,
		) );
	}

}
