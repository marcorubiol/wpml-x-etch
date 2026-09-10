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

		// Include component property defaults — walking group/repeater/condition nesting.
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
			// composite props (Etch-serialized groups and repeaters) via the prop-def tree.
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

	/**
	 * Read a prop definition's primitive/specialized pair.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function prop_type( array $prop ): array {
		$type = $prop['type'] ?? array();
		if ( ! is_array( $type ) ) {
			return array( '', '' );
		}
		$primitive   = $type['primitive'] ?? '';
		$specialized = $type['specialized'] ?? '';

		return array(
			is_string( $primitive ) ? $primitive : '',
			is_string( $specialized ) ? $specialized : '',
		);
	}

	public static function is_translatable_prop_type( array $prop ): bool {
		[ $primitive, $specialized ] = self::prop_type( $prop );

		return 'string' === $primitive && '' === $specialized;
	}

	/**
	 * Group and repeater props are the composite types: both nest sub-property
	 * definitions and both store their value as a wrapped JSON payload.
	 */
	public static function is_composite_prop( array $prop ): bool {
		[ $primitive, $specialized ] = self::prop_type( $prop );

		return ( 'object' === $primitive && 'group' === $specialized )
			|| ( 'array' === $primitive && 'repeater' === $specialized );
	}

	/** Repeater props hold a LIST of items; groups hold a single keyed map. */
	public static function is_repeater_prop( array $prop ): bool {
		[ $primitive, $specialized ] = self::prop_type( $prop );

		return 'array' === $primitive && 'repeater' === $specialized;
	}

	/** Condition wrappers are transparent: their children live at the parent level. */
	public static function is_condition_prop( array $prop ): bool {
		[ , $specialized ] = self::prop_type( $prop );

		return 'condition' === $specialized;
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
	 * Decode an Etch composite prop value into an array.
	 *
	 * Etch wraps both composite payloads in an extra brace pair: groups as
	 * "{{...json object...}}" and repeaters as "{[...json array...]}".
	 * Composites nested inside either appear as embedded strings in the same
	 * format. Returns null when the value is in neither format.
	 */
	public static function decode_composite_value( string $value ): ?array {
		$is_group    = str_starts_with( $value, '{{' ) && str_ends_with( $value, '}}' );
		$is_repeater = str_starts_with( $value, '{[' ) && str_ends_with( $value, ']}' );
		if ( ! $is_group && ! $is_repeater ) {
			return null;
		}
		$decoded = json_decode( substr( $value, 1, -1 ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Re-serialize a decoded composite value in Etch's exact format.
	 *
	 * The builder emits `{${JSON.stringify(value)}}` for groups and repeaters
	 * alike, so one encoder covers both shapes; JSON_UNESCAPED_SLASHES +
	 * JSON_UNESCAPED_UNICODE match its output. Callers that rewrite a STORED
	 * value must round-trip-verify first.
	 */
	public static function encode_composite_value( array $data ): string {
		return '{' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '}';
	}

	/**
	 * Replace translated strings inside a decoded composite payload.
	 *
	 * Walks group maps and repeater item lists alike, recursing into embedded
	 * composite strings. A nested composite is only rewritten when re-encoding
	 * reproduces it byte-for-byte, so an unexpected serialization variant is
	 * left intact rather than corrupted.
	 *
	 * @param array<mixed>          $data         Decoded composite payload.
	 * @param array<string, string> $translations Map of original => translated.
	 * @param bool                  $changed      Set to true when a value was replaced.
	 * @return array<mixed>
	 */
	public static function translate_composite_data( array $data, array $translations, bool &$changed ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::translate_composite_data( $value, $translations, $changed );
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}

			$decoded = self::decode_composite_value( $value );
			if ( null === $decoded ) {
				if ( isset( $translations[ $value ] ) && $translations[ $value ] !== $value ) {
					$data[ $key ] = $translations[ $value ];
					$changed      = true;
				}
				continue;
			}

			if ( self::encode_composite_value( $decoded ) !== $value ) {
				continue;
			}

			$nested_changed = false;
			$walked         = self::translate_composite_data( $decoded, $translations, $nested_changed );
			if ( $nested_changed ) {
				$data[ $key ] = self::encode_composite_value( $walked );
				$changed      = true;
			}
		}

		return $data;
	}

	/**
	 * Collect translatable prop DEFAULTS, recursing into composite props
	 * (group / repeater) and condition wrappers, whose children hold the
	 * real defaults.
	 *
	 * A composite prop contributes text from two places, and Etch renders
	 * both: the defaults of its leaf sub-props, used per key and per repeater
	 * item whenever the instance leaves one unset, and the payload of its own
	 * `default`, which Etch takes as the base value when the instance leaves
	 * the whole prop unset.
	 */
	private function collect_default_values( array $props, array &$values ): void {
		foreach ( $props as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}

			if ( self::is_condition_prop( $prop ) ) {
				$children = $prop['properties'] ?? array();
				if ( is_array( $children ) ) {
					$this->collect_default_values( $children, $values );
				}
				continue;
			}

			if ( self::is_composite_prop( $prop ) ) {
				$children = $prop['properties'] ?? array();
				if ( ! is_array( $children ) ) {
					continue;
				}
				$this->collect_default_values( $children, $values );
				$this->collect_from_composite_value(
					$prop['default'] ?? null,
					$this->build_translatable_tree( $children ),
					$values
				);
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
	 *   key => array( ... )     — composite prop (nested keys inside)
	 *
	 * Groups and repeaters share one node shape: a repeater's node describes a
	 * single ITEM, and the walker applies it to every item in the list.
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
			if ( self::is_condition_prop( $prop ) ) {
				$children = $prop['properties'] ?? array();
				if ( is_array( $children ) ) {
					$tree = $tree + $this->build_translatable_tree( $children );
				}
				continue;
			}

			$key = $prop['key'] ?? '';
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( self::is_composite_prop( $prop ) ) {
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
	 * decoding Etch composite serialization for nested props.
	 */
	private function collect_from_instance_values( array $attrs, array $tree, array &$values ): void {
		foreach ( $attrs as $key => $v ) {
			$node = $tree[ $key ] ?? null;
			if ( null === $node ) {
				continue;
			}

			if ( true === $node ) {
				if ( is_string( $v ) && self::is_collectable_text( $v ) ) {
					$values[] = $v;
				}
				continue;
			}

			$this->collect_from_composite_value( $v, $node, $values );
		}
	}

	/**
	 * Walk a composite prop value against its sub-tree.
	 *
	 * Accepts either the wrapped string Etch stores in block attributes and
	 * prop defaults, or an already-decoded array — repeater items arrive that
	 * way. A keyed map is a group and is walked directly; a list is a repeater,
	 * whose items each match the same sub-tree.
	 */
	private function collect_from_composite_value( mixed $value, array $tree, array &$values ): void {
		$decoded = is_string( $value ) ? self::decode_composite_value( $value ) : $value;
		if ( ! is_array( $decoded ) ) {
			return;
		}

		if ( array_is_list( $decoded ) ) {
			foreach ( $decoded as $item ) {
				$this->collect_from_composite_value( $item, $tree, $values );
			}
			return;
		}

		$this->collect_from_instance_values( $decoded, $tree, $values );
	}
}
