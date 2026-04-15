<?php
/**
 * Expose WPML language data as Etch Dynamic Data.
 *
 * The Loop Manager preset is managed via the panel toggle
 * (BuilderPanel::handle_toggle_loop_preset). This class only
 * keeps existing preset data in sync on each page load.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Etch;

use WpmlXEtch\Core\SubscriberInterface;

class DynamicLanguageData implements SubscriberInterface {

	private const LOOP_PRESET_ID = 'wpml-languages';

	public static function getSubscribedEvents(): array {
		return array(
			'etch/dynamic_data/option' => 'add_language_data',
			array( 'init', 'sync_loop_preset', 20 ),
		);
	}

	public function add_language_data( array $data ): array {
		static $languages = null;

		if ( null === $languages ) {
			$raw = apply_filters( 'wpml_active_languages', null, array(
				'skip_missing' => 0,
			) );
			$languages = ! empty( $raw ) && is_array( $raw )
				? array_values( $raw )
				: array();
		}

		if ( ! empty( $languages ) ) {
			$data['wpml_languages'] = $languages;
		}

		return $data;
	}

	/**
	 * Keep existing loop preset data in sync with current WPML languages.
	 * Only updates if the preset was already activated via the panel toggle.
	 */
	public function sync_loop_preset(): void {
		if ( ! get_option( 'zs_wxe_loop_preset_active', false ) ) {
			return;
		}

		// Read raw option WITHOUT the translate_loops filter to prevent
		// translated loop data from being written back as source data.
		global $wpdb;
		$raw_option = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'etch_loops' LIMIT 1" );
		$loops = $raw_option ? maybe_unserialize( $raw_option ) : array();
		if ( ! is_array( $loops ) ) {
			$loops = array();
		}

		$raw       = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$languages = ( ! empty( $raw ) && is_array( $raw ) ) ? array_values( $raw ) : array();

		// Remove URLs — they're resolved at runtime by LoopTranslator
		// based on the current page context. Storing them here would
		// save home URLs (no page context at init) which confuse users.
		foreach ( $languages as &$lang ) {
			unset( $lang['url'] );
		}
		unset( $lang );

		// Remove preset if WPML is deactivated.
		if ( empty( $languages ) ) {
			unset( $loops[ self::LOOP_PRESET_ID ] );
			update_option( 'etch_loops', $loops );
			return;
		}

		// Skip update if data is identical.
		$existing_data = $loops[ self::LOOP_PRESET_ID ]['config']['data'] ?? null;
		if ( $existing_data === $languages ) {
			return;
		}

		// Create or update the preset.
		$loops[ self::LOOP_PRESET_ID ] = array(
			'name'   => 'WPML Languages',
			'key'    => 'wpmlLanguages',
			'global' => true,
			'config' => array(
				'type' => 'json',
				'data' => $languages,
			),
		);
		update_option( 'etch_loops', $loops );
	}
}
