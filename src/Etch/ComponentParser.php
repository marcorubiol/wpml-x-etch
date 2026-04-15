<?php
/**
 * Parser for Etch blocks and components.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Etch;

/**
 * Handles parsing of Etch blocks to extract translatable values.
 */
class ComponentParser {

	/**
	 * Get all translatable values from a post.
	 *
	 * @param int $post_id The post ID to parse.
	 * @return string[]
	 */
	public function get_translatable_values( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$values = array();
		$blocks = parse_blocks( (string) $post->post_content );
		$this->collect_translatable_values_from_blocks( $blocks, $values );

		// Include component property defaults.
		if ( 'wp_block' === get_post_type( $post_id ) ) {
			$props = get_post_meta( $post_id, 'etch_component_properties', true );
			if ( is_array( $props ) ) {
				foreach ( $props as $prop ) {
					if ( ! self::is_translatable_prop_type( $prop ) ) {
						continue;
					}
					$default = $prop['default'] ?? null;
					if ( is_string( $default ) && '' !== $default
						&& self::is_translatable_value( $default )
						&& ! preg_match( \WpmlXEtch\Core\Plugin::DYNAMIC_EXPR_PATTERN, $default )
						&& ! preg_match( '/^[a-zA-Z_]+\.[a-zA-Z_.]+$/', $default ) ) {
						$values[] = $default;
					}
				}
			}
		}

		$values = array_values( array_filter( $values, 'is_string' ) );
		sort( $values );

		return $values;
	}

	private function collect_translatable_values_from_blocks( array $blocks, array &$values, array &$prop_cache = array() ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			// Etch text blocks: collect text content (skip dynamic expressions).
			if ( 'etch/text' === $block['blockName'] ) {
				$text = $block['attrs']['content'] ?? '';
				if ( is_string( $text ) && '' !== trim( $text )
					&& ! preg_match( \WpmlXEtch\Core\Plugin::DYNAMIC_EXPR_PATTERN, trim( $text ) )
					&& ! preg_match( '/^[a-zA-Z_]+\.[a-zA-Z_.]+$/', trim( $text ) ) ) {
					$values[] = trim( $text );
				}
			}

			// Etch component blocks: collect instance attribute values.
			if ( 'etch/component' === $block['blockName'] ) {
				$inst_attrs = $block['attrs']['attributes'] ?? array();
				if ( is_array( $inst_attrs ) ) {
					$ref            = (int) ( $block['attrs']['ref'] ?? 0 );
					$allowed_keys   = $ref ? $this->get_translatable_keys( $ref, $prop_cache ) : array();

					foreach ( $inst_attrs as $key => $v ) {
						if ( ! isset( $allowed_keys[ $key ] ) ) {
							continue;
						}
						if ( is_string( $v ) && '' !== $v
							&& self::is_translatable_value( $v )
							&& ! preg_match( \WpmlXEtch\Core\Plugin::DYNAMIC_EXPR_PATTERN, $v )
							&& ! preg_match( '/^[a-zA-Z_]+\.[a-zA-Z_.]+$/', $v ) ) {
							$values[] = $v;
						}
					}
				}
			}

			// Etch element blocks: collect static href values (skip dynamic expressions).
			if ( 'etch/element' === $block['blockName'] ) {
				$href = $block['attrs']['attributes']['href'] ?? '';
				if ( is_string( $href ) && '' !== $href
					&& ! preg_match( \WpmlXEtch\Core\Plugin::DYNAMIC_EXPR_PATTERN, $href )
					&& ! preg_match( '/^[a-zA-Z_]+\.[a-zA-Z_.]+$/', $href ) ) {
					$values[] = $href;
				}
			}

			$inner = $block['innerBlocks'] ?? array();
			if ( ! empty( $inner ) ) {
				$this->collect_translatable_values_from_blocks( $inner, $values, $prop_cache );
			}
		}
	}

	/**
	 * Extract etch/component ref IDs from parsed blocks.
	 *
	 * @param array $blocks Parsed blocks from parse_blocks().
	 * @return int[] Component IDs keyed and valued by ID.
	 */
	public function extract_component_refs( array $blocks ): array {
		$refs = array();

		foreach ( $blocks as $block ) {
			if ( 'etch/component' === ( $block['blockName'] ?? '' ) ) {
				$ref = (int) ( $block['attrs']['ref'] ?? 0 );
				if ( $ref ) {
					$refs[ $ref ] = $ref;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				// Use union (+) instead of array_merge to preserve int keys and deduplicate.
				$refs = $refs + $this->extract_component_refs( $block['innerBlocks'] );
			}
		}

		return $refs;
	}

	public function has_etch_blocks( \WP_Post $post ): bool {
		return str_contains( $post->post_content, '<!-- wp:etch/' );
	}

	/** Reject values that look like numbers, CSS units, hex colors, or short codes. */
	private static function is_translatable_value( string $value ): bool {
		// Numbers: 5, 3.5, 40, 0.75
		if ( preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			return false;
		}
		// CSS units: 1em, 5rem, 100%, 12px, 50vh, etc.
		if ( preg_match( '/^\d+(\.\d+)?(px|em|rem|%|vh|vw|vmin|vmax|ch|ex|svh|svw|dvh|dvw|lvh|lvw)$/i', $value ) ) {
			return false;
		}
		// Hex colors: #fff, #a3b2c1
		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
			return false;
		}
		// Too short to be real text (single char, two chars)
		if ( strlen( $value ) <= 2 ) {
			return false;
		}
		return true;
	}

	private static function is_translatable_prop_type( array $prop ): bool {
		$type        = $prop['type'] ?? array();
		$primitive   = $type['primitive'] ?? '';
		$specialized = $type['specialized'] ?? '';

		return 'string' === $primitive && '' === $specialized;
	}

	private function get_translatable_keys( int $component_id, array &$cache ): array {
		if ( isset( $cache[ $component_id ] ) ) {
			return $cache[ $component_id ];
		}

		$allow = array();
		$props = get_post_meta( $component_id, 'etch_component_properties', true );

		if ( is_array( $props ) ) {
			foreach ( $props as $prop ) {
				$key = $prop['key'] ?? '';
				if ( '' !== $key && self::is_translatable_prop_type( $prop ) ) {
					$allow[ $key ] = true;
				}
			}
		}

		$cache[ $component_id ] = $allow;
		return $allow;
	}
}
