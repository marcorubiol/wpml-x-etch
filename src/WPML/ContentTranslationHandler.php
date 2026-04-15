<?php
/**
 * Applies Etch string translations to post_content.
 *
 * Two hooks:
 * 1. wpml_page_builder_string_translated (priority 11) — runs right after
 *    WPML's Gutenberg handler (priority 10) overwrites the translated post's
 *    content with the original. We read the freshly-written content and layer
 *    Etch translations on top.
 * 2. wpml_pro_translation_completed (priority 20) — fallback for ATE
 *    completion when the Gutenberg handler did not fire.
 *
 * Writes go through wpml_update_escaped_post() (WPML's canonical post writer
 * used by every page-builder addon) so the language context is switched and
 * the wpmldev-672 term-cache workaround is applied. wp_update_post() is kept
 * as a fallback for installations where WPML is missing or downgraded.
 *
 * Queries icl_strings, icl_string_translations, and icl_string_packages
 * directly because WPML's string API does not expose package-scoped
 * bulk translation lookups.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\WPML;

use WpmlXEtch\Core\SubscriberInterface;
use WpmlXEtch\Utils\Logger;

/**
 * Handles applying Etch string translations to post_content.
 */
class ContentTranslationHandler implements SubscriberInterface {

	public static function getSubscribedEvents(): array {
		return array(
			array( 'wpml_page_builder_string_translated', 'fix_gutenberg_overwrite', 11, 5 ),
			array( 'wpml_pro_translation_completed', 'on_translation_completed', 20, 3 ),
		);
	}

	/**
	 * Hook: wpml_page_builder_string_translated (priority 11).
	 *
	 * Fires right after WPML's Gutenberg handler (priority 10) writes the
	 * original post_content to the translated post. We re-read the post,
	 * apply Etch translations, and save — undoing the overwrite.
	 *
	 * Only acts when kind='Gutenberg' and the post contains Etch blocks.
	 */
	public function fix_gutenberg_overwrite(
		string $kind,
		int $translated_post_id,
		\WP_Post $original_post,
		array $string_translations,
		string $lang
	): void {
		if ( 'Gutenberg' !== $kind ) {
			return;
		}

		if ( ! str_contains( $original_post->post_content, '<!-- wp:etch/' ) ) {
			return;
		}

		$this->apply_etch_translations( $original_post->ID, $translated_post_id, $lang );
	}

	/**
	 * Hook: wpml_pro_translation_completed (priority 20).
	 *
	 * Fallback for ATE completion — applies Etch translations even when
	 * the Gutenberg handler did not fire for this post.
	 */
	public function on_translation_completed( int $translated_post_id, array $fields, object $job ): void {
		$original_post_id = (int) ( $job->original_doc_id ?? 0 );
		if ( ! $original_post_id ) {
			return;
		}

		$translated_post = get_post( $translated_post_id );
		if ( ! $translated_post ) {
			return;
		}

		$lang = apply_filters( 'wpml_element_language_code', null, array(
			'element_id'   => $translated_post_id,
			'element_type' => 'post_' . $translated_post->post_type,
		) );

		if ( ! $lang ) {
			return;
		}

		$this->apply_etch_translations( $original_post_id, $translated_post_id, $lang );
	}

	/**
	 * Apply Etch translations to a single translated post's content.
	 */
	public function apply_etch_translations( int $original_post_id, int $translated_post_id, string $lang ): void {
		$translations = $this->get_etch_translations( $original_post_id, $lang );

		if ( empty( $translations ) ) {
			Logger::debug( 'No Etch translations, writing original content as-is', array(
				'original_post_id'   => $original_post_id,
				'translated_post_id' => $translated_post_id,
				'lang'               => $lang,
			) );
		}

		// Always rebuild from original — translated post may contain stale content.
		$original_post = get_post( $original_post_id );
		if ( ! $original_post ) {
			return;
		}

		$blocks  = parse_blocks( $original_post->post_content );
		$blocks  = $this->replace_translations_in_blocks( $blocks, $translations );
		$content = serialize_blocks( $blocks );

		// Use WPML's canonical post writer when available: it switches language
		// context and applies the wpmldev-672 term-cache workaround. Falls back
		// to wp_update_post if the helper is missing (e.g. WPML disabled).
		$postarr = array(
			'ID'           => $translated_post_id,
			'post_content' => $content,
		);
		if ( function_exists( 'wpml_update_escaped_post' ) ) {
			$result = wpml_update_escaped_post( $postarr, $lang, true );
		} else {
			$result = wp_update_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			Logger::warning( 'Failed to update translated post content', array(
				'translated_post_id' => $translated_post_id,
				'original_post_id'   => $original_post_id,
				'error'              => $result->get_error_message(),
			) );
			return;
		}

		Logger::info( 'Applied Etch translations to post_content', array(
			'translated_post_id' => $translated_post_id,
			'original_post_id'   => $original_post_id,
			'lang'               => $lang,
			'translation_count'  => count( $translations ),
		) );
	}

	/**
	 * Query completed Etch translations for a post's string package.
	 *
	 * @return array<string, string> Map of original value → translated value.
	 */
	public function get_etch_translations( int $original_post_id, string $lang ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.value AS original, st.value AS translated
			 FROM {$wpdb->prefix}icl_strings s
			 JOIN {$wpdb->prefix}icl_string_packages p ON s.string_package_id = p.ID
			 JOIN {$wpdb->prefix}icl_string_translations st ON st.string_id = s.id
			 WHERE p.kind = %s AND p.post_id = %d
			   AND st.language = %s AND st.status = 10",
			StringHandler::PACKAGE_KIND,
			$original_post_id,
			$lang
		) );

		if ( ! $rows ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			if ( $row->original !== $row->translated ) {
				$map[ $row->original ] = $row->translated;
			}
		}

		return $map;
	}

	/**
	 * Recursively walk blocks and replace Etch attribute values with translations.
	 *
	 * @param array<string,string> $translations Map of original → translated.
	 */
	private function replace_translations_in_blocks( array $blocks, array $translations ): array {
		foreach ( $blocks as &$block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			switch ( $block['blockName'] ) {
				case 'etch/text':
					$original = $block['attrs']['content'] ?? '';
					if ( is_string( $original ) && isset( $translations[ $original ] ) ) {
						$block['attrs']['content'] = $translations[ $original ];
					}
					break;

				case 'etch/component':
					$inst_attrs = $block['attrs']['attributes'] ?? array();
					if ( is_array( $inst_attrs ) ) {
						foreach ( $inst_attrs as $key => $value ) {
							if ( is_string( $value ) && isset( $translations[ $value ] ) ) {
								$inst_attrs[ $key ] = $translations[ $value ];
							}
						}
						$block['attrs']['attributes'] = $inst_attrs;
					}
					break;

				case 'etch/element':
					$el_attrs = $block['attrs']['attributes'] ?? array();
					if ( is_array( $el_attrs ) ) {
						foreach ( $el_attrs as $key => $value ) {
							if ( is_string( $value ) && isset( $translations[ $value ] ) ) {
								$el_attrs[ $key ] = $translations[ $value ];
							}
						}
						$block['attrs']['attributes'] = $el_attrs;
					}
					break;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->replace_translations_in_blocks( $block['innerBlocks'], $translations );
			}
		}

		return $blocks;
	}
}
