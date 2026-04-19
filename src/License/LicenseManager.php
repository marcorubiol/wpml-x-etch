<?php
/**
 * License management: activation, deactivation, cached validation via LemonSqueezy.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\License;

use WP_Error;

class LicenseManager {

	private const OPT_KEY  = 'zs_wxe_license_key';
	private const OPT_DATA = 'zs_wxe_license_data';

	/** Revalidate against LemonSqueezy after this many seconds. */
	private const VALIDATE_TTL = 7 * DAY_IN_SECONDS;

	/** Grace period after a revalidation failure before falling back. */
	private const GRACE_PERIOD = DAY_IN_SECONDS;

	/**
	 * Activate a license key against LemonSqueezy.
	 *
	 * @return array{tier: string, email: string, expires_at: string|null, instance_id: string}|WP_Error
	 */
	/** @var array<string, array{tier: string, email: string}> Dev-only test keys (WP_DEBUG required). */
	private const TEST_KEYS = array(
		'test-supporter-key' => array( 'tier' => 'supporter', 'email' => 'supporter@test.local' ),
		'test-pro-key'       => array( 'tier' => 'pro',       'email' => 'pro@test.local' ),
	);

	public function activate( string $key ): array|WP_Error {
		$key = sanitize_text_field( trim( $key ) );
		if ( '' === $key ) {
			return new WP_Error( 'empty_key', __( 'License key is required.', 'wpml-x-etch' ), array( 'status' => 400 ) );
		}

		// Deactivate previous license silently before activating a new one.
		$old_key = $this->get_stored_key();
		if ( '' !== $old_key && $old_key !== $key ) {
			$this->deactivate();
		}

		// Dev-only test keys — bypass LemonSqueezy API.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( self::TEST_KEYS[ $key ] ) ) {
			return $this->activate_test_key( $key, self::TEST_KEYS[ $key ] );
		}

		$response = wp_remote_post( 'https://api.lemonsqueezy.com/v1/licenses/activate', array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'license_key'   => $key,
				'instance_name' => $this->instance_name(),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'activation_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['activated'] ) ) {
			$msg = $body['error'] ?? $body['message'] ?? __( 'Activation failed.', 'wpml-x-etch' );
			return new WP_Error( 'activation_rejected', $msg, array( 'status' => $code ?: 400 ) );
		}

		$meta        = $body['meta'] ?? array();
		$instance    = $body['instance'] ?? array();
		$instance_id = $instance['id'] ?? '';
		$tier        = $this->resolve_tier( $meta );
		$email       = $meta['customer_email'] ?? '';
		$expires_at  = $meta['expires_at'] ?? null;

		$this->store_key( $key );
		$this->store_data( array(
			'tier'         => $tier,
			'email'        => sanitize_email( $email ),
			'expires_at'   => $expires_at,
			'validated_at' => time(),
			'instance_id'  => $instance_id,
		) );

		return array(
			'tier'        => $tier,
			'email'       => $email,
			'expires_at'  => $expires_at,
			'instance_id' => $instance_id,
		);
	}

	/**
	 * Deactivate the stored license.
	 */
	public function deactivate(): bool|WP_Error {
		$key  = $this->get_stored_key();
		$data = $this->get_stored_data();

		if ( '' === $key ) {
			delete_option( self::OPT_KEY );
			delete_option( self::OPT_DATA );
			return true;
		}

		// Test keys: skip API call.
		if ( isset( self::TEST_KEYS[ $key ] ) ) {
			delete_option( self::OPT_KEY );
			delete_option( self::OPT_DATA );
			return true;
		}

		if ( ! empty( $data['instance_id'] ) ) {
			$response = wp_remote_post( 'https://api.lemonsqueezy.com/v1/licenses/deactivate', array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'license_key' => $key,
					'instance_id' => $data['instance_id'],
				) ),
			) );

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'deactivation_failed', $response->get_error_message(), array( 'status' => 502 ) );
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$msg  = $body['error'] ?? $body['message'] ?? __( 'Deactivation failed.', 'wpml-x-etch' );
				return new WP_Error( 'deactivation_rejected', $msg, array( 'status' => $code ?: 400 ) );
			}
		}

		delete_option( self::OPT_KEY );
		delete_option( self::OPT_DATA );

		return true;
	}

	/**
	 * Get current license status for the frontend.
	 *
	 * @return array{tier: string|null, email: string, expires_at: string|null, is_valid: bool, key_masked: string}
	 */
	public function get_status(): array {
		$data = $this->get_stored_data();
		$key  = $this->get_stored_key();

		if ( '' === $key || empty( $data ) ) {
			return array(
				'tier'       => null,
				'email'      => '',
				'expires_at' => null,
				'is_valid'   => false,
				'key_masked' => '',
			);
		}

		$is_valid = $this->is_license_valid( $data );

		return array(
			'tier'       => $data['tier'] ?? null,
			'email'      => $data['email'] ?? '',
			'expires_at' => $data['expires_at'] ?? null,
			'is_valid'   => $is_valid,
			'key_masked' => $this->mask_key( $key ),
		);
	}

	/**
	 * Return the validated tier or null to fall through to filter default.
	 */
	public function validate_cached(): ?string {
		$data = $this->get_stored_data();
		$key  = $this->get_stored_key();

		if ( '' === $key || empty( $data ) ) {
			return null;
		}

		if ( ! $this->is_license_valid( $data ) ) {
			return null;
		}

		// Re-validate if cache is stale.
		$validated_at = $data['validated_at'] ?? 0;
		if ( ( time() - $validated_at ) > self::VALIDATE_TTL ) {
			$refreshed = $this->remote_validate( $key );
			if ( is_wp_error( $refreshed ) ) {
				// Grace period: keep tier for 24h after failed revalidation.
				if ( ( time() - $validated_at ) > ( self::VALIDATE_TTL + self::GRACE_PERIOD ) ) {
					return null;
				}
				return $data['tier'] ?? null;
			}
			$data = $refreshed;
		}

		return $data['tier'] ?? null;
	}

	/**
	 * Revalidate a key against LemonSqueezy /validate endpoint.
	 *
	 * @return array|WP_Error Updated data array on success.
	 */
	private function remote_validate( string $key ): array|WP_Error {
		// Test keys: refresh validated_at without hitting API.
		if ( isset( self::TEST_KEYS[ $key ] ) ) {
			$data = $this->get_stored_data();
			$data['validated_at'] = time();
			$this->store_data( $data );
			return $data;
		}

		$response = wp_remote_post( 'https://api.lemonsqueezy.com/v1/licenses/validate', array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'license_key'   => $key,
				'instance_name' => $this->instance_name(),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['valid'] ) ) {
			return new WP_Error( 'validation_failed', $body['error'] ?? 'Validation failed' );
		}

		$meta = $body['meta'] ?? array();
		$data = array(
			'tier'         => $this->resolve_tier( $meta ),
			'email'        => sanitize_email( $meta['customer_email'] ?? '' ),
			'expires_at'   => $meta['expires_at'] ?? null,
			'validated_at' => time(),
			'instance_id'  => $this->get_stored_data()['instance_id'] ?? '',
		);

		$this->store_data( $data );
		return $data;
	}

	/**
	 * Map LemonSqueezy product/variant metadata to a tier string.
	 *
	 * LemonSqueezy returns variant_name in the license meta. Map it to our tiers.
	 * Default to 'pro' — a paid license should always grant at least pro.
	 */
	/**
	 * Activate a dev-only test key without hitting LemonSqueezy.
	 */
	private function activate_test_key( string $key, array $test_data ): array {
		$tier  = $test_data['tier'];
		$email = $test_data['email'];

		$this->store_key( $key );
		$this->store_data( array(
			'tier'         => $tier,
			'email'        => $email,
			'expires_at'   => null,
			'validated_at' => time(),
			'instance_id'  => 'test-' . $tier,
		) );

		return array(
			'tier'        => $tier,
			'email'       => $email,
			'expires_at'  => null,
			'instance_id' => 'test-' . $tier,
		);
	}

	private function resolve_tier( array $meta ): string {
		$variant = strtolower( $meta['variant_name'] ?? '' );

		if ( str_contains( $variant, 'supporter' ) ) {
			return 'supporter';
		}

		return 'pro';
	}

	private function is_license_valid( array $data ): bool {
		if ( ! empty( $data['expires_at'] ) ) {
			try {
				$expires = new \DateTimeImmutable( $data['expires_at'], new \DateTimeZone( 'UTC' ) );
				$now     = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
				if ( $expires < $now ) {
					return false;
				}
			} catch ( \Exception $e ) {
				return false;
			}
		}
		return true;
	}

	private function instance_name(): string {
		return wp_parse_url( site_url(), PHP_URL_HOST ) ?: site_url();
	}

	private function mask_key( string $key ): string {
		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
	}

	private function store_key( string $key ): void {
		if ( function_exists( 'wp_encrypt' ) ) {
			$key = wp_encrypt( $key );
		}
		update_option( self::OPT_KEY, $key );
	}

	private function get_stored_key(): string {
		$stored = (string) get_option( self::OPT_KEY, '' );
		if ( '' === $stored ) {
			return '';
		}
		if ( function_exists( 'wp_decrypt' ) ) {
			$decrypted = wp_decrypt( $stored );
			return is_string( $decrypted ) ? $decrypted : $stored;
		}
		return $stored;
	}

	private function store_data( array $data ): void {
		update_option( self::OPT_DATA, $data );
	}

	private function get_stored_data(): array {
		$data = get_option( self::OPT_DATA, array() );
		return is_array( $data ) ? $data : array();
	}
}
