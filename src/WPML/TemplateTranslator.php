<?php
/**
 * WPML template translation handler.
 *
 * Queries icl_translations (slug → original ID lookup) and
 * icl_strings / icl_string_translations / icl_string_packages
 * (translated prop defaults). The WPML filter API does not expose
 * slug-based lookups for wp_template, nor package-scoped string
 * translations, so direct queries are required.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\WPML;

use WpmlXEtch\Core\SubscriberInterface;
use WpmlXEtch\Etch\ComponentParser;
use WpmlXEtch\Utils\Logger;

/**
 * Handles translation of wp_template posts and component references.
 */
class TemplateTranslator implements SubscriberInterface {

	public static function getSubscribedEvents(): array {
		return array(
			array( 'render_block_data', 'translate_component_ref', 10, 2 ),
			array( 'pre_get_block_templates', 'pre_get_block_templates', 10, 3 ),
			array( 'pre_get_block_template', 'pre_get_block_template', 10, 3 ),
			array( 'posts_pre_query', 'translate_wp_template_query', 10, 2 ),
			array( 'rest_request_after_callbacks', 'translate_template_in_rest', 10, 3 ),
		);
	}

	private array $template_cache = array();
	private array $prop_defaults_cache = array();

	/**
	 * Translate etch/component ref to current language's wp_block post
	 * and inject translated property defaults for unset props, at every
	 * nesting level the component defines.
	 */
	public function translate_component_ref( array $parsed_block, array $source_block ): array {
		if ( ( $parsed_block['blockName'] ?? '' ) !== 'etch/component' ) {
			return $parsed_block;
		}

		$ref = (int) ( $parsed_block['attrs']['ref'] ?? 0 );
		if ( ! $ref ) {
			return $parsed_block;
		}

		$translated_ref = (int) apply_filters( 'wpml_object_id', $ref, 'wp_block', true );
		if ( $translated_ref && $translated_ref !== $ref ) {
			Logger::debug( 'Translated component ref', array(
				'original_ref'   => $ref,
				'translated_ref' => $translated_ref,
			) );
			$parsed_block['attrs']['ref'] = $translated_ref;
		}

		// Inject translated default values for props not explicitly set.
		$current_lang = apply_filters( 'wpml_current_language', null );
		$default_lang = apply_filters( 'wpml_default_language', null );
		if ( $current_lang && $default_lang && $current_lang !== $default_lang ) {
			$this->inject_translated_prop_defaults( $ref, $current_lang, $parsed_block );
		}

		return $parsed_block;
	}

	private function inject_translated_prop_defaults( int $ref, string $current_lang, array &$parsed_block ): void {
		global $wpdb;

		// Cache Etch translations for this component (original → translated).
		$cache_key = $ref . '|' . $current_lang;
		if ( ! array_key_exists( $cache_key, $this->prop_defaults_cache ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.value AS original, st.value AS translated
				 FROM {$wpdb->prefix}icl_string_translations st
				 JOIN {$wpdb->prefix}icl_strings s ON s.id = st.string_id
				 JOIN {$wpdb->prefix}icl_string_packages p ON p.ID = s.string_package_id
				 WHERE p.post_id = %d
				   AND p.kind   = %s
				   AND st.language = %s
				   AND st.status   = 10",
				$ref,
				StringHandler::PACKAGE_KIND,
				$current_lang
			) );
			$this->prop_defaults_cache[ $cache_key ] = array();
			foreach ( $rows as $row ) {
				if ( $row->original !== $row->translated ) {
					$this->prop_defaults_cache[ $cache_key ][ $row->original ] = $row->translated;
				}
			}
		}

		$prop_defs = get_post_meta( $ref, 'etch_component_properties', true ) ?: array();
		if ( ! is_array( $prop_defs ) ) {
			return;
		}
		$inst_attrs = $parsed_block['attrs']['attributes'] ?? array();
		if ( ! is_array( $inst_attrs ) ) {
			$inst_attrs = array();
		}

		$this->inject_defaults( $prop_defs, $inst_attrs, $this->prop_defaults_cache[ $cache_key ] );

		$parsed_block['attrs']['attributes'] = $inst_attrs;
	}

	/**
	 * Fill props the instance leaves unset with their translated defaults,
	 * walking the same nesting the string extractor registers.
	 *
	 * Three shapes, mirroring ComponentParser:
	 * - Condition wrappers are transparent: their children sit at this level.
	 * - Composite props (group / repeater) keep their value as an Etch-
	 *   serialized payload under their own key. When the instance leaves one
	 *   unset, Etch falls back to the prop's own default payload and then to
	 *   each leaf's default, so both are translated here.
	 * - Leaves take their translated default directly.
	 *
	 * Values the instance sets explicitly are never touched: those are already
	 * translated in the translated post's content. Likewise a composite whose
	 * instance value is not a decodable payload — a dynamic binding, say — is
	 * left exactly as it is.
	 *
	 * Only the parsed block being rendered is mutated, never stored content,
	 * so re-encoding a payload cannot corrupt anything on disk.
	 *
	 * @param array<string, string> $map Original => translated.
	 * @return bool True when at least one value was injected.
	 */
	private function inject_defaults( array $prop_defs, array &$attrs, array $map ): bool {
		$changed = false;

		foreach ( $prop_defs as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$children = $prop['properties'] ?? array();
			$children = is_array( $children ) ? $children : array();

			if ( ComponentParser::is_condition_prop( $prop ) ) {
				if ( $this->inject_defaults( $children, $attrs, $map ) ) {
					$changed = true;
				}
				continue;
			}

			$key = $prop['key'] ?? '';
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( ComponentParser::is_composite_prop( $prop ) ) {
				if ( empty( $children ) ) {
					continue;
				}
				if ( $this->inject_composite_defaults( $prop, $children, $key, $attrs, $map ) ) {
					$changed = true;
				}
				continue;
			}

			if ( isset( $attrs[ $key ] ) || ! ComponentParser::is_translatable_prop_type( $prop ) ) {
				continue;
			}
			$default = $prop['default'] ?? null;
			if ( ! is_string( $default ) || '' === $default ) {
				continue;
			}
			if ( preg_match( \WpmlXEtch\Core\Plugin::DYNAMIC_EXPR_PATTERN, $default ) ) {
				continue;
			}
			$translated = $map[ $default ] ?? null;
			if ( $translated ) {
				$attrs[ $key ] = $translated;
				$changed       = true;
			}
		}

		return $changed;
	}

	/**
	 * Inject translated defaults into one composite prop's payload.
	 *
	 * @param array<string, string> $map Original => translated.
	 * @return bool True when the payload was rewritten.
	 */
	private function inject_composite_defaults( array $prop, array $children, string $key, array &$attrs, array $map ): bool {
		$payload_changed = false;

		if ( isset( $attrs[ $key ] ) ) {
			$payload = is_string( $attrs[ $key ] )
				? ComponentParser::decode_composite_value( $attrs[ $key ] )
				: $attrs[ $key ];
			if ( ! is_array( $payload ) ) {
				return false;
			}
		} else {
			$default = $prop['default'] ?? null;
			$payload = is_string( $default ) ? ComponentParser::decode_composite_value( $default ) : $default;
			$payload = is_array( $payload ) ? $payload : array();
			if ( ! empty( $payload ) ) {
				$payload = ComponentParser::translate_composite_data( $payload, $map, $payload_changed );
			}
		}

		if ( ComponentParser::is_repeater_prop( $prop ) ) {
			foreach ( $payload as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( $this->inject_defaults( $children, $item, $map ) ) {
					$payload[ $index ] = $item;
					$payload_changed   = true;
				}
			}
		} elseif ( $this->inject_defaults( $children, $payload, $map ) ) {
			$payload_changed = true;
		}

		if ( ! $payload_changed ) {
			return false;
		}

		$attrs[ $key ] = ComponentParser::encode_composite_value( $payload );

		return true;
	}

	/** Hook: pre_get_block_templates — swap slugs to translated wp_template posts. */
	public function pre_get_block_templates( mixed $templates, array $query, string $template_type ): mixed {
		if ( 'wp_template' !== $template_type || empty( $query['slug__in'] ) ) {
			return $templates;
		}

		$current_lang = apply_filters( 'wpml_current_language', null );
		$default_lang = apply_filters( 'wpml_default_language', null );
		if ( ! $current_lang || ! $default_lang || $current_lang === $default_lang ) {
			return $templates;
		}

		$results = array();
		foreach ( $query['slug__in'] as $slug ) {
			$translated_id = $this->get_translated_template_id( $slug, $current_lang, $default_lang );
			if ( ! $translated_id ) {
				continue;
			}

			$translated_post = get_post( $translated_id );
			if ( ! $translated_post ) {
				continue;
			}

			$result = _build_block_template_result_from_post( $translated_post );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			$result->id   = get_stylesheet() . '//' . $slug;
			$result->slug = $slug;

			$results[] = $result;
		}

		return ! empty( $results ) ? $results : $templates;
	}

	/** Hook: pre_get_block_template — single template lookup by theme//slug ID. */
	public function pre_get_block_template( mixed $template, string $id, string $template_type ): mixed {
		if ( 'wp_template' !== $template_type ) {
			return $template;
		}

		$current_lang = apply_filters( 'wpml_current_language', null );
		$default_lang = apply_filters( 'wpml_default_language', null );
		if ( ! $current_lang || ! $default_lang || $current_lang === $default_lang ) {
			return $template;
		}

		$parts = explode( '//', $id, 2 );
		if ( count( $parts ) < 2 ) {
			return $template;
		}
		$slug = $parts[1];

		$translated_id = $this->get_translated_template_id( $slug, $current_lang, $default_lang );
		if ( ! $translated_id ) {
			Logger::warning( 'No translated template found', array(
				'slug'         => $slug,
				'current_lang' => $current_lang,
			) );
			return $template;
		}

		$translated_post = get_post( $translated_id );
		if ( ! $translated_post ) {
			return $template;
		}

		$result = _build_block_template_result_from_post( $translated_post );
		if ( is_wp_error( $result ) ) {
			return $template;
		}

		$result->id   = $id;
		$result->slug = $slug;

		return $result;
	}

	/** Hook: posts_pre_query — translate wp_template queries by slug. */
	public function translate_wp_template_query( ?array $posts, \WP_Query $query ): ?array {
		if ( 'wp_template' !== $query->get( 'post_type' ) ) {
			return $posts;
		}

		$slug = $query->get( 'name' );
		if ( ! $slug ) {
			return $posts;
		}

		$current_lang = apply_filters( 'wpml_current_language', null );
		$default_lang = apply_filters( 'wpml_default_language', null );
		if ( ! $current_lang || ! $default_lang || $current_lang === $default_lang ) {
			return $posts;
		}

		$translated_id = $this->get_translated_template_id( $slug, $current_lang, $default_lang );
		if ( ! $translated_id ) {
			return $posts;
		}

		$translated = get_post( $translated_id );
		return $translated ? array( $translated ) : $posts;
	}

	/** Swap template ID/slug/title in Etch REST responses for the post's language. */
	public function translate_template_in_rest( mixed $response, mixed $handler, \WP_REST_Request $request ): mixed {
		if ( ! ( $response instanceof \WP_REST_Response ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['template'] ) || ! is_array( $data['template'] ) || empty( $data['template']['id'] ) ) {
			return $response;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id ) {
			return $response;
		}

		$post_type    = get_post_type( $post_id );
		$lang         = apply_filters( 'wpml_element_language_code', null, array(
			'element_id'   => $post_id,
			'element_type' => 'post_' . $post_type,
		) );
		$default_lang = apply_filters( 'wpml_default_language', null );

		if ( ! $lang || $lang === $default_lang ) {
			return $response;
		}

		$template_id   = (int) $data['template']['id'];
		$translated_id = (int) apply_filters( 'wpml_object_id', $template_id, 'wp_template', false, $lang );

		if ( ! $translated_id || $translated_id === $template_id ) {
			return $response;
		}

		$data['template']['id']    = $translated_id;
		$data['template']['slug']  = get_post_field( 'post_name', $translated_id );
		$data['template']['title'] = get_the_title( $translated_id );
		$response->set_data( $data );

		return $response;
	}

	private function get_translated_template_id( string $slug, string $current_lang, string $default_lang ): int {
		$key = "{$slug}|{$current_lang}|{$default_lang}";
		if ( array_key_exists( $key, $this->template_cache ) ) {
			return $this->template_cache[ $key ];
		}

		global $wpdb;
		$en_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = 'post_wp_template'
			 WHERE p.post_type = 'wp_template' AND p.post_status = 'publish'
			   AND p.post_name = %s AND t.language_code = %s
			 LIMIT 1",
			$slug,
			$default_lang
		) );

		$translated_id = 0;
		if ( $en_id ) {
			$translated = (int) apply_filters( 'wpml_object_id', $en_id, 'wp_template', false, $current_lang );
			if ( $translated && $translated !== $en_id ) {
				$translated_id = $translated;
			}
		}

		return $this->template_cache[ $key ] = $translated_id;
	}
}
