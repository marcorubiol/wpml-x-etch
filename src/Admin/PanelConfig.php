<?php
/**
 * Panel configuration: pills, locking, loops, switcher preset.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Admin;

use WpmlXEtch\License\LicenseManager;

class PanelConfig {

	/** Resolve the active locking mode: constant → license → filter default. */
	public static function get_locking_mode(): string {
		if ( defined( 'ZS_WXE_LOCKING_MODE' ) ) {
			return ZS_WXE_LOCKING_MODE;
		}
		$license_tier = LicenseManager::get_instance()->validate_cached();
		if ( $license_tier ) {
			return $license_tier;
		}
		return apply_filters( 'zs_wxe_locking_mode', 'supporter' );
	}

	/** Pill ID → unlocked. Filterable via `zs_wxe_pill_access`. */
	public function get_pill_access(): array {
		$locking_mode = self::get_locking_mode();

		$custom_cpts = $this->get_translated_custom_post_types();

		if ( 'pro' === $locking_mode ) {
			$access = array(
				'on-this-page' => true,
				'wp_template'  => true,
				'wp_block'     => true,
				'json-loops'   => true,
				'page'         => true,
				'post'         => true,
				'ai'           => true,
			);

			foreach ( $custom_cpts as $name ) {
				$access[ $name ] = true;
			}

			return apply_filters( 'zs_wxe_pill_access', $access );
		}

		if ( 'supporter' === $locking_mode ) {
			$access = array(
				'on-this-page' => true,
				'wp_template'  => true,
				'wp_block'     => true,
				'json-loops'   => true,
				'page'         => true,
				'post'         => true,
				'ai'           => false,
			);

			foreach ( $custom_cpts as $name ) {
				$access[ $name ] = true;
			}

			return apply_filters( 'zs_wxe_pill_access', $access );
		}

		// FREE mode.
		$access = array(
			'on-this-page' => true,
			'wp_template'  => false,
			'wp_block'     => false,
			'json-loops'   => false,
			'page'         => false,
			'post'         => false,
			'ai'           => false,
		);

		foreach ( $custom_cpts as $name ) {
			$access[ $name ] = false;
		}

		return apply_filters( 'zs_wxe_pill_access', $access );
	}

	/** Custom post types that are public, translated, and not handled as built-in pills. */
	private function get_translated_custom_post_types(): array {
		$skip  = array( 'attachment', 'wp_template', 'wp_block', 'page', 'post' );
		$names = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( in_array( $pt->name, $skip, true ) ) {
				continue;
			}
			if ( apply_filters( 'wpml_is_translated_post_type', null, $pt->name ) ) {
				$names[] = $pt->name;
			}
		}

		return $names;
	}

	public function get_content_type_pills(): array {
		$access = $this->get_pill_access();

		$locking_mode = self::get_locking_mode();
		$is_unlocked = in_array( $locking_mode, array( 'pro', 'supporter' ), true );

		$pills = array(
			array(
				'id'     => 'on-this-page',
				'label'  => __( 'Current Context', 'wpml-x-etch' ),
				'locked' => empty( $access['on-this-page'] ),
			),
			array(
				'id'     => 'wp_template',
				'label'  => __( 'Templates', 'wpml-x-etch' ),
				'locked' => empty( $access['wp_template'] ),
			),
			array(
				'id'     => 'wp_block',
				'label'  => __( 'Components', 'wpml-x-etch' ),
				'locked' => empty( $access['wp_block'] ),
			),
			array(
				'id'           => 'json-loops',
				'label'        => __( 'JSON Loops', 'wpml-x-etch' ),
				'locked'       => empty( $access['json-loops'] ),
				'dividerAfter' => true,
			),
		);

		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$builtins   = array();
		$custom     = array();

		foreach ( $post_types as $pt ) {
			if ( in_array( $pt->name, array( 'attachment', 'wp_template', 'wp_block' ), true ) ) {
				continue;
			}
			if ( ! apply_filters( 'wpml_is_translated_post_type', null, $pt->name ) ) {
				continue;
			}
			if ( in_array( $pt->name, array( 'page', 'post' ), true ) ) {
				$builtins[] = $pt;
			} else {
				$custom[] = $pt;
			}
		}

		usort( $builtins, fn( $a, $b ) => 'page' === $a->name ? -1 : ( 'page' === $b->name ? 1 : 0 ) );

		foreach ( $builtins as $pt ) {
			$pills[] = array(
				'id'     => $pt->name,
				'label'  => $pt->labels->name,
				'locked' => empty( $access[ $pt->name ] ),
			);
		}

		usort( $custom, fn( $a, $b ) => strcasecmp( $a->labels->name, $b->labels->name ) );

		if ( ! empty( $custom ) && ! empty( $pills ) ) {
			$pills[ count( $pills ) - 1 ]['dividerAfter'] = true;
		}

		foreach ( $custom as $pt ) {
			$pills[] = array(
				'id'     => $pt->name,
				'label'  => $pt->labels->name,
				'locked' => empty( $access[ $pt->name ] ),
			);
		}

		$existing_ids     = array_column( $pills, 'id' );
		$non_translatable = array();
		foreach ( $post_types as $pt ) {
			if ( in_array( $pt->name, array( 'attachment', 'wp_template', 'wp_block' ), true ) ) {
				continue;
			}
			if ( apply_filters( 'wpml_is_translated_post_type', null, $pt->name ) ) {
				continue;
			}
			if ( in_array( $pt->name, $existing_ids, true ) ) {
				continue;
			}
			$non_translatable[] = $pt;
		}

		usort( $non_translatable, fn( $a, $b ) => strcasecmp( $a->labels->name, $b->labels->name ) );

		if ( ! empty( $non_translatable ) && empty( $custom ) && ! empty( $pills ) ) {
			$pills[ count( $pills ) - 1 ]['dividerAfter'] = true;
		}

		foreach ( $non_translatable as $pt ) {
			$pills[] = array(
				'id'              => $pt->name,
				'label'           => $pt->labels->name,
				'locked'          => ! $is_unlocked,
				'notTranslatable' => true,
			);
		}

		return $pills;
	}

	public function get_json_loops( int $post_id ): array {
		$loops = get_option( 'etch_loops', array() );
		if ( ! is_array( $loops ) ) {
			return array();
		}

		$post     = get_post( $post_id );
		$used_ids = array();
		if ( $post && ! empty( $post->post_content ) ) {
			$blocks   = parse_blocks( $post->post_content );
			$used_ids = $this->extract_loop_ids( $blocks );
		}

		$st_base = admin_url( 'admin.php?page=wpml-string-translation/menu/string-translation.php&context=Etch+JSON+Loops' );
		$result  = array();

		foreach ( $loops as $loop_id => $loop ) {
			if ( 'wpml-languages' === $loop_id ) {
				continue;
			}

			$type = $loop['config']['type'] ?? '';
			if ( 'json' !== $type ) {
				continue;
			}

			$name       = $loop['name'] ?? $loop_id;
			$item_count = is_array( $loop['config']['data'] ?? null ) ? count( $loop['config']['data'] ) : 0;

			$result[] = array(
				'id'         => $loop_id,
				'name'       => $name,
				'itemCount'  => $item_count,
				'onThisPage' => in_array( $loop_id, $used_ids, true ),
				'url'        => $st_base . '&search=' . rawurlencode( $name . ' ›' ),
			);
		}

		return $result;
	}

	/** @return array Loop IDs found in blocks (follows component refs). */
	public function extract_loop_ids( array $blocks, array &$visited_refs = array() ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';

			if ( 'etch/loop' === $block_name && ! empty( $block['attrs']['loopId'] ) ) {
				$ids[] = $block['attrs']['loopId'];
			}

			if ( 'etch/component' === $block_name && ! empty( $block['attrs']['ref'] ) ) {
				$ref = (int) $block['attrs']['ref'];
				if ( ! in_array( $ref, $visited_refs, true ) ) {
					$visited_refs[] = $ref;
					$ref_post = get_post( $ref );
					if ( $ref_post && ! empty( $ref_post->post_content ) ) {
						$ref_blocks = parse_blocks( $ref_post->post_content );
						$ids = array_merge( $ids, $this->extract_loop_ids( $ref_blocks, $visited_refs ) );
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, $this->extract_loop_ids( $block['innerBlocks'], $visited_refs ) );
			}
		}
		return array_unique( $ids );
	}

	public function get_switcher_component_json(): string {
		$path = dirname( ZS_WXE_PLUGIN_FILE ) . '/assets/switcher-component.json';
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '{}';
	}

	public function is_loop_preset_active(): bool {
		return (bool) get_option( 'zs_wxe_loop_preset_active', false );
	}

	public function handle_toggle_loop_preset(): array {
		$active = get_option( 'zs_wxe_loop_preset_active', false );

		if ( $active ) {
			update_option( 'zs_wxe_loop_preset_active', false );
			$loops = get_option( 'etch_loops', array() );
			if ( is_array( $loops ) && isset( $loops['wpml-languages'] ) ) {
				unset( $loops['wpml-languages'] );
				update_option( 'etch_loops', $loops );
			}
			return array( 'active' => false );
		}

		update_option( 'zs_wxe_loop_preset_active', true );
		$raw       = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$languages = ( ! empty( $raw ) && is_array( $raw ) ) ? array_values( $raw ) : array();

		$loops = get_option( 'etch_loops', array() );
		if ( ! is_array( $loops ) ) {
			$loops = array();
		}

		$loops['wpml-languages'] = array(
			'name'   => 'WPML Languages',
			'key'    => 'wpmlLanguages',
			'global' => true,
			'config' => array(
				'type' => 'json',
				'data' => $languages,
			),
		);

		update_option( 'etch_loops', $loops );
		return array( 'active' => true );
	}
}

