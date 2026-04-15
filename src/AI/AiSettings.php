<?php
/**
 * AI translation settings: provider, API key, tone, glossary.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\AI;

class AiSettings {

	// Options.
	private const OPT_PROVIDER = 'zs_wxe_ai_provider';
	private const OPT_API_KEY  = 'zs_wxe_ai_api_key';
	private const OPT_TONE     = 'zs_wxe_ai_tone';
	private const OPT_GLOSSARY = 'zs_wxe_ai_glossary';
	private const OPT_VERIFIED = 'zs_wxe_ai_verified';

	public function get_provider(): string {
		return (string) get_option( self::OPT_PROVIDER, '' );
	}

	public function get_api_key(): string {
		$stored = (string) get_option( self::OPT_API_KEY, '' );
		if ( ! $stored ) {
			return '';
		}
		// Try wp_decrypt (WP 6.8+), fall back to raw.
		if ( function_exists( 'wp_decrypt' ) ) {
			$decrypted = wp_decrypt( $stored );
			return is_string( $decrypted ) ? $decrypted : $stored;
		}
		return $stored;
	}

	public function get_tone(): string {
		return (string) get_option( self::OPT_TONE, 'formal' );
	}

	public function get_glossary(): array {
		$glossary = get_option( self::OPT_GLOSSARY, array() );
		return is_array( $glossary ) ? $glossary : array();
	}

	public function is_configured(): bool {
		return '' !== $this->get_provider() && '' !== $this->get_api_key();
	}

	public function save_settings( array $data ): void {
		if ( isset( $data['provider'] ) ) {
			$provider = sanitize_text_field( $data['provider'] );
			if ( in_array( $provider, array( 'claude', 'openai', '' ), true ) ) {
				update_option( self::OPT_PROVIDER, $provider );
			}
		}

		if ( ! empty( $data['api_key_clear'] ) ) {
			delete_option( self::OPT_API_KEY );
			delete_option( self::OPT_VERIFIED );
		} elseif ( isset( $data['api_key'] ) && '' !== $data['api_key'] ) {
			update_option( self::OPT_VERIFIED, false );
			$key = sanitize_text_field( $data['api_key'] );
			if ( function_exists( 'wp_encrypt' ) ) {
				$key = wp_encrypt( $key );
			}
			update_option( self::OPT_API_KEY, $key );
		}

		if ( isset( $data['tone'] ) ) {
			$tone = sanitize_text_field( $data['tone'] );
			if ( in_array( $tone, array( 'formal', 'informal' ), true ) ) {
				update_option( self::OPT_TONE, $tone );
			}
		}

		if ( isset( $data['glossary'] ) && is_array( $data['glossary'] ) ) {
			$clean = array();
			foreach ( $data['glossary'] as $entry ) {
				if ( ! empty( $entry['source'] ) && ! empty( $entry['target'] ) ) {
					$clean[] = array(
						'source' => sanitize_text_field( $entry['source'] ),
						'target' => sanitize_text_field( $entry['target'] ),
						'lang'   => sanitize_text_field( $entry['lang'] ?? '' ),
					);
				}
			}
			update_option( self::OPT_GLOSSARY, $clean );
		}
	}

	public function is_verified(): bool {
		return (bool) get_option( self::OPT_VERIFIED, false );
	}

	public function set_verified( bool $verified ): void {
		update_option( self::OPT_VERIFIED, $verified );
	}

	public function get_settings_for_js(): array {
		return array(
			'provider' => $this->get_provider(),
			'hasKey'   => '' !== $this->get_api_key(),
			'tone'     => $this->get_tone(),
			'glossary' => $this->get_glossary(),
			'verified' => $this->is_verified(),
		);
	}

	public function test_connection(): array|\WP_Error {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'not_configured', 'AI provider not configured', array( 'status' => 400 ) );
		}
		$client = new AiClient( $this );
		$result = $client->test();
		if ( ! is_wp_error( $result ) ) {
			$this->set_verified( true );
		}
		return $result;
	}
}
