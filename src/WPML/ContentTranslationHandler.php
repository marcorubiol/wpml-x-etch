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
 * Pending translations: while any string has neither a completed nor a
 * carried-over ("needs update") translation, the translated post keeps its
 * previous content instead of rendering source-language text. That content is
 * captured on pre_post_update, because WPML's own writers overwrite the
 * translated post with the original before our hooks run.
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

	/** @var array<int, string> Post content as it was before this request first rewrote it. */
	private array $previous_content = array();

	public static function getSubscribedEvents(): array {
		return array(
			array( 'pre_post_update', 'remember_previous_content', 10, 1 ),
			array( 'wpml_page_builder_string_translated', 'fix_gutenberg_overwrite', 11, 5 ),
			array( 'wpml_pro_translation_completed', 'on_translation_completed', 20, 3 ),
		);
	}

	/**
	 * Hook: pre_post_update — remember an Etch post's content before the first
	 * write of this request, so a pending translation can be kept even after
	 * WPML has overwritten the post with the original.
	 */
	public function remember_previous_content( int $post_id ): void {
		if ( isset( $this->previous_content[ $post_id ] ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( $post && str_contains( $post->post_content, '<!-- wp:etch/' ) ) {
			$this->previous_content[ $post_id ] = $post->post_content;
		}
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
		// Always rebuild from original — translated post may contain stale content.
		$original_post = get_post( $original_post_id );
		if ( ! $original_post ) {
			return;
		}

		// Skip posts whose post_content has no Etch blocks (e.g. Classic Editor or
		// plain Gutenberg pages rendered through an Etch template via {@post-content}).
		// Without this guard, the always-write below overwrites WPML's translated
		// post_content with the original, undoing the translation.
		if ( ! str_contains( $original_post->post_content, '<!-- wp:etch/' ) ) {
			return;
		}

		[ 'translations' => $translations, 'pending' => $pending ] = StringHandler::get_package_translations( $original_post_id, $lang );

		if ( empty( $translations ) ) {
			Logger::debug( 'No Etch translations, writing original content as-is', array(
				'original_post_id'   => $original_post_id,
				'translated_post_id' => $translated_post_id,
				'lang'               => $lang,
			) );
		}

		$blocks         = parse_blocks( $original_post->post_content );
		$source_content = serialize_blocks( $blocks );
		$blocks         = $this->replace_translations_in_blocks( $blocks, $translations );
		$content        = serialize_blocks( $blocks );

		if ( $pending > 0 && StringHandler::keeps_previous_translation() ) {
			$previous = $this->get_previous_translation( $translated_post_id, $original_post->post_content, $source_content );
			if ( null !== $previous ) {
				Logger::info( 'Kept previous translation: strings pending', array(
					'original_post_id'   => $original_post_id,
					'translated_post_id' => $translated_post_id,
					'lang'               => $lang,
					'pending'            => $pending,
				) );
				$content = $previous;
			}
		}

		/**
		 * Filter the translated post_content right before it is written.
		 *
		 * @param string                $content            Content about to be written.
		 * @param int                   $original_post_id   Original post ID.
		 * @param int                   $translated_post_id Translated post ID.
		 * @param string                $lang               Language code of the translated post.
		 * @param array<string, string> $translations       Map of original => translated values applied.
		 */
		$content = (string) apply_filters( 'zs_wxe_translated_post_content', $content, $original_post_id, $translated_post_id, $lang, $translations );

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

		// What this request wrote is now the translation to keep if a later
		// pass in the same request finds strings pending.
		$this->previous_content[ $translated_post_id ] = $content;

		Logger::info( 'Applied Etch translations to post_content', array(
			'translated_post_id' => $translated_post_id,
			'original_post_id'   => $original_post_id,
			'lang'               => $lang,
			'translation_count'  => count( $translations ),
		) );
	}

	/**
	 * The translated post's previous Etch content, or null when there is none
	 * worth keeping — no Etch blocks yet, or just a copy of the original (a new
	 * translation WPML created from the source).
	 */
	private function get_previous_translation( int $translated_post_id, string $original_content, string $source_content ): ?string {
		$previous = $this->previous_content[ $translated_post_id ] ?? get_post( $translated_post_id )?->post_content;

		if ( ! is_string( $previous )
			|| ! str_contains( $previous, '<!-- wp:etch/' )
			|| $previous === $original_content
			|| $previous === $source_content ) {
			return null;
		}

		return $previous;
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
				case 'etch/raw-html':
					$original = $block['attrs']['content'] ?? '';
					if ( is_string( $original ) && isset( $translations[ $original ] ) ) {
						$block['attrs']['content'] = $translations[ $original ];
					}
					break;

				case 'etch/component':
					$inst_attrs = $block['attrs']['attributes'] ?? array();
					if ( is_array( $inst_attrs ) ) {
						foreach ( $inst_attrs as $key => $value ) {
							if ( is_string( $value ) ) {
								$inst_attrs[ $key ] = $this->translate_prop_value( $value, $translations );
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

	/**
	 * Translate a component instance-attribute value, recursing into Etch's
	 * composite serialization: groups ("{{...}}") and repeaters ("{[...]}").
	 *
	 * Plain strings are looked up in the translations map directly. Composite
	 * values are decoded, their string leaves translated recursively, and
	 * re-encoded — but only when re-encoding reproduces the input
	 * byte-for-byte (round-trip guard), so an unexpected serialization
	 * variant is left intact rather than corrupted.
	 */
	private function translate_prop_value( string $value, array $translations ): string {
		$decoded = \WpmlXEtch\Etch\ComponentParser::decode_composite_value( $value );
		if ( null === $decoded ) {
			return $translations[ $value ] ?? $value;
		}

		if ( \WpmlXEtch\Etch\ComponentParser::encode_composite_value( $decoded ) !== $value ) {
			Logger::warning( 'Skipping composite prop translation: round-trip mismatch', array(
				'value_start' => substr( $value, 0, 80 ),
			) );
			return $value;
		}

		$changed = false;
		$walked  = \WpmlXEtch\Etch\ComponentParser::translate_composite_data( $decoded, $translations, $changed );

		return $changed ? \WpmlXEtch\Etch\ComponentParser::encode_composite_value( $walked ) : $value;
	}
}
