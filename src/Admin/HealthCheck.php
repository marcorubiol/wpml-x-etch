<?php
/**
 * WPML dependency health check.
 *
 * Verifies that critical WPML tables, classes, and functions are available.
 * Shows an admin notice if any dependency is missing.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Admin;

class HealthCheck {

	private const CACHE_KEY = 'zs_wxe_health_check';
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** @var string[] */
	private const REQUIRED_TABLES = array(
		'icl_translations',
		'icl_translation_status',
		'icl_translate_job',
		'icl_translate',
		'icl_strings',
		'icl_string_translations',
		'icl_string_packages',
	);

	/**
	 * Run the health check and show admin notice if anything fails.
	 *
	 * Skips AJAX and REST API requests.
	 */
	public function init(): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'maybe_run_check' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Run the check if the cached result has expired.
	 */
	public function maybe_run_check(): void {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return;
		}

		$failures = $this->run_checks();
		set_transient( self::CACHE_KEY, $failures, self::CACHE_TTL );
	}

	/**
	 * Show the admin notice when there are failures.
	 */
	public function render_notice(): void {
		$failures = get_transient( self::CACHE_KEY );
		if ( empty( $failures ) ) {
			return;
		}

		$list = implode( ', ', array_map( function ( string $item ): string {
			return '<code>' . esc_html( $item ) . '</code>';
		}, $failures ) );

		printf(
			'<div class="notice notice-error is-dismissible"><p><strong>WPML x Etch:</strong> %s %s</p></div>',
			esc_html__( 'Missing WPML dependencies:', 'wpml-x-etch' ),
			$list // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	/**
	 * Check all WPML dependencies and return a list of failures.
	 *
	 * @return string[] Names of missing dependencies (empty if all OK).
	 */
	private function run_checks(): array {
		$failures = array();

		$failures = array_merge( $failures, $this->check_tables() );
		$failures = array_merge( $failures, $this->check_classes_and_functions() );

		return $failures;
	}

	/**
	 * Verify that critical WPML tables exist.
	 *
	 * @return string[]
	 */
	private function check_tables(): array {
		global $wpdb;

		$missing = array();

		foreach ( self::REQUIRED_TABLES as $table ) {
			$full_name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name )
			);
			if ( null === $result ) {
				$missing[] = $table . ' (table)';
			}
		}

		return $missing;
	}

	/**
	 * Verify that critical WPML classes and functions exist.
	 *
	 * @return string[]
	 */
	private function check_classes_and_functions(): array {
		$missing = array();

		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$missing[] = 'ICL_SITEPRESS_VERSION (WPML core)';
		}

		if ( ! defined( 'WPML_ST_VERSION' ) ) {
			$missing[] = 'WPML_ST_VERSION (String Translation)';
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		global $iclTranslationManagement;
		if ( empty( $iclTranslationManagement ) ) {
			$missing[] = '$iclTranslationManagement (Translation Management)';
		}

		if ( ! function_exists( 'icl_register_string' ) ) {
			$missing[] = 'icl_register_string() (String Translation API)';
		}

		return $missing;
	}
}
