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
	 * Clear the cached health-check result so the next admin load re-runs it.
	 *
	 * Called on plugin activation so a reactivation forces a fresh check
	 * instead of showing a stale failure for up to CACHE_TTL.
	 */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
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
			'<div class="notice notice-error is-dismissible"><p><strong>WPML x Etch:</strong> %s %s</p>%s</div>',
			esc_html__( 'Missing WPML dependencies:', 'wpml-x-etch' ),
			$list, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$this->render_guidance() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
		);
	}

	/**
	 * Build the "how to fix it yourself" guidance shown under the notice.
	 *
	 * These tables/classes belong to WPML, so the repair must happen on the
	 * WPML side. We point the user at the right place instead of touching
	 * WPML's schema ourselves.
	 *
	 * @return string HTML (already escaped).
	 */
	private function render_guidance(): string {
		// WPML core present but something (e.g. a String Translation table)
		// is missing: the install/migration is incomplete and can be repaired
		// from WPML's own troubleshooting tools — no support ticket needed.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$url  = admin_url( 'admin.php?page=sitepress-multilingual-cms/menu/troubleshooting.php' );
			$steps = sprintf(
				/* translators: 1: WPML String Translation, 2: button label on WPML's troubleshooting page. */
				esc_html__( 'WPML is active but its database is incomplete. To fix it yourself: deactivate and reactivate %1$s, or open WPML → Support → Troubleshooting and click “%2$s”.', 'wpml-x-etch' ),
				'<strong>WPML String Translation</strong>',
				esc_html__( 'Set up WPML tables again', 'wpml-x-etch' )
			);

			return sprintf(
				'<p>%s</p><p><a class="button button-secondary" href="%s">%s</a></p>',
				$steps, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				esc_url( $url ),
				esc_html__( 'Open WPML Troubleshooting', 'wpml-x-etch' )
			);
		}

		// WPML core itself is missing: nothing to repair, it must be installed.
		return sprintf(
			'<p>%s</p>',
			esc_html__( 'Install and activate WPML Multilingual CMS together with the String Translation add-on, then this notice will clear automatically.', 'wpml-x-etch' )
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
