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

		// Include component property defaults — walking group/condition nesting.
		if ( 'wp_block' === get_post_type( $post_id ) ) {
			$props = get_post_meta( $post_id, 'etch_component_properties', true );
			if ( is_array( $props ) ) {
				$this->collect_default_values( $props, $values );
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

			// Etch text / raw-html blocks: collect content (skip dynamic expressions).
			if ( 'etch/text' === $block['blockName'] || 'etch/raw-html' === $block['blockName'] ) {
				$text = $block['attrs']['content'] ?? '';
				if ( is_string( $text ) && self::is_collectable_text( $text ) ) {
					$values[] = $text;
				}
			}

			// Etch component blocks: collect instance attribute values, walking
			// group props (Etch-serialized nested objects) via the prop-def tree.
			if ( 'etch/component' === $block['blockName'] ) {
				$inst_attrs = $block['attrs']['attributes'] ?? array();
				if ( is_array( $inst_attrs ) ) {
					$ref  = (int) ( $block['attrs']['ref'] ?? 0 );
					$tree = $ref ? $this->get_translatable_tree( $ref, $prop_cache ) : array();
					$this->collect_from_instance_values( $inst_attrs, $tree, $values );
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

	/** Shared text filters: non-empty, real text, not a dynamic expression or dotted path. */
	private static function is_collectable_text( string $value ): bool {
		$trimmed = trim( $value );
		return '' !== $trimmed
			&& self::is_translatable_value( $trimmed )
			&& ! preg_match( \WpmlXEtch\Core\Plugin::DYNAMIC_EXPR_PATTERN, $trimmed )
			&& ! preg_match( '/^[a-zA-Z_]+\.[a-zA-Z_.]+$/', $trimmed );
	}

	/**
	 * Decode an Etch group-prop value ("{{...json...}}") into an array.
	 *
	 * Etch serializes object/group prop values as JSON wrapped in an extra
	 * brace pair; nested groups appear as embedded strings in the same format.
	 * Returns null when the value is not in that format.
	 */
	public static function decode_group_value( string $value ): ?array {
		if ( ! str_starts_with( $value, '{{' ) || ! str_ends_with( $value, '}}' ) ) {
			return null;
		}
		$decoded = json_decode( substr( $value, 1, -1 ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Re-serialize a decoded group value in Etch's exact format.
	 *
	 * JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE match Etch's builder
	 * output; callers must round-trip-verify before rewriting stored values.
	 */
	public static function encode_group_value( array $data ): string {
		return '{' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '}';
	}

	/**
	 * Collect translatable prop DEFAULTS, recursing into group props and
	 * condition wrappers (whose children hold the real defaults).
	 */
	private function collect_default_values( array $props, array &$values ): void {
		foreach ( $props as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$type        = $prop['type'] ?? array();
			$primitive   = $type['primitive'] ?? '';
			$specialized = $type['specialized'] ?? '';

			if ( 'condition' === $specialized || ( 'object' === $primitive && 'group' === $specialized ) ) {
				$children = $prop['properties'] ?? array();
				if ( is_array( $children ) ) {
					$this->collect_default_values( $children, $values );
				}
				continue;
			}

			if ( ! self::is_translatable_prop_type( $prop ) ) {
				continue;
			}
			$default = $prop['default'] ?? null;
			if ( is_string( $default ) && self::is_collectable_text( $default ) ) {
				$values[] = $default;
			}
		}
	}

	/**
	 * Build the translatable-leaf tree for a component's prop definitions,
	 * mirroring the shape of INSTANCE attribute values:
	 *   key => true             — translatable string leaf
	 *   key => array( ... )     — group prop (nested keys inside)
	 *
	 * Condition-specialized wrappers are transparent in the data model —
	 * their children live at the parent level in instance values (e.g. the
	 * `lede` prop nested under a condition arrives as attributes["lede"]) —
	 * so their properties merge into the current level without a path segment.
	 */
	private function build_translatable_tree( array $props ): array {
		$tree = array();
		foreach ( $props as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$type        = $prop['type'] ?? array();
			$primitive   = $type['primitive'] ?? '';
			$specialized = $type['specialized'] ?? '';

			if ( 'condition' === $specialized ) {
				$children = $prop['properties'] ?? array();
				if ( is_array( $children ) ) {
					$tree = $tree + $this->build_translatable_tree( $children );
				}
				continue;
			}

			$key = (string) ( $prop['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}

			if ( 'object' === $primitive && 'group' === $specialized ) {
				$children = $prop['properties'] ?? array();
				$subtree  = is_array( $children ) ? $this->build_translatable_tree( $children ) : array();
				if ( ! empty( $subtree ) ) {
					$tree[ $key ] = $subtree;
				}
				continue;
			}

			if ( self::is_translatable_prop_type( $prop ) ) {
				$tree[ $key ] = true;
			}
		}
		return $tree;
	}

	private function get_translatable_tree( int $component_id, array &$cache ): array {
		if ( isset( $cache[ $component_id ] ) ) {
			return $cache[ $component_id ];
		}

		$props = get_post_meta( $component_id, 'etch_component_properties', true );
		$tree  = is_array( $props ) ? $this->build_translatable_tree( $props ) : array();

		$cache[ $component_id ] = $tree;
		return $tree;
	}

	/**
	 * Collect translatable values from component INSTANCE attributes,
	 * decoding Etch group serialization for nested props.
	 */
	private function collect_from_instance_values( array $attrs, array $tree, array &$values ): void {
		foreach ( $attrs as $key => $v ) {
			$node = $tree[ $key ] ?? null;
			if ( null === $node || ! is_string( $v ) ) {
				continue;
			}

			if ( true === $node ) {
				if ( self::is_collectable_text( $v ) ) {
					$values[] = $v;
				}
				continue;
			}

			$decoded = self::decode_group_value( $v );
			if ( is_array( $decoded ) ) {
				$this->collect_from_instance_values( $decoded, $node, $values );
			}
		}
	}
}
