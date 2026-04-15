<?php
/**
 * REST API routes for translation panel.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\RestApi;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpmlXEtch\Admin\BuilderPanel;
use WpmlXEtch\AI\AiTranslationHandler;
use WpmlXEtch\License\LicenseManager;

/**
 * Thin REST layer — validates/extracts params and delegates to BuilderPanel.
 */
class TranslationRoutes extends BaseRoute {

	private readonly BuilderPanel $panel;
	private readonly AiTranslationHandler $ai_handler;

	public function __construct( BuilderPanel $panel, AiTranslationHandler $ai_handler ) {
		$this->panel      = $panel;
		$this->ai_handler = $ai_handler;
	}

	protected function get_routes(): array {
		return array(
			array(
				'route'    => '/translate-url',
				'methods'  => 'GET',
				'callback' => array( $this, 'get_translate_url' ),
				'args'     => array(
					'post_id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'target_lang'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'component_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			),
			array(
				'route'    => '/languages-status',
				'methods'  => 'GET',
				'callback' => array( $this, 'get_languages_status' ),
				'args'     => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'route'    => '/components',
				'methods'  => 'GET',
				'callback' => array( $this, 'get_all_components' ),
				'args'     => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'route'    => '/posts-by-type',
				'methods'  => 'GET',
				'callback' => array( $this, 'get_posts_by_type' ),
				'args'     => array(
					'post_type' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			array(
				'route'    => '/pill-statuses',
				'methods'  => 'GET',
				'callback' => array( $this, 'get_pill_statuses' ),
			),
			array(
				'route'    => '/toggle-loop-preset',
				'methods'  => 'POST',
				'callback' => array( $this, 'toggle_loop_preset' ),
			),
			array(
				'route'    => '/resync',
				'methods'  => 'POST',
				'callback' => array( $this, 'resync' ),
				'args'     => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'route'    => '/resync/all',
				'methods'  => 'POST',
				'callback' => array( $this, 'resync_all' ),
			),
			array(
				'route'    => '/resync/status',
				'methods'  => 'GET',
				'callback' => array( $this, 'get_resync_status' ),
			),
			array(
				'route'    => '/ai/settings',
				'methods'  => 'GET',
				'callback' => array( $this, 'get_ai_settings' ),
			),
			array(
				'route'    => '/ai/settings',
				'methods'  => 'POST',
				'callback' => array( $this, 'save_ai_settings' ),
			),
			array(
				'route'    => '/ai/test',
				'methods'  => 'POST',
				'callback' => array( $this, 'test_ai_connection' ),
			),
			array(
				'route'    => '/ai-translate',
				'methods'  => 'POST',
				'callback' => array( $this, 'ai_translate' ),
				'args'     => array(
					'post_id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'target_lang'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'component_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'force'        => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					),
				),
			),
			array(
				'route'    => '/ai-translate-all',
				'methods'  => 'POST',
				'callback' => array( $this, 'ai_translate_all' ),
				'args'     => array(
					'post_id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'component_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'force'        => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					),
				),
			),
			array(
				'route'    => '/ai-translate-loop',
				'methods'  => 'POST',
				'callback' => array( $this, 'ai_translate_loop' ),
				'args'     => array(
					'loop_id'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'target_lang' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'force'       => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					),
				),
			),
			array(
				'route'    => '/ai-translate-loop-all',
				'methods'  => 'POST',
				'callback' => array( $this, 'ai_translate_loop_all' ),
				'args'     => array(
					'loop_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'force'   => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					),
				),
			),
			array(
				'route'    => '/license/activate',
				'methods'  => 'POST',
				'callback' => array( $this, 'license_activate' ),
				'args'     => array(
					'key' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			array(
				'route'    => '/license/deactivate',
				'methods'  => 'POST',
				'callback' => array( $this, 'license_deactivate' ),
			),
			array(
				'route'    => '/license/status',
				'methods'  => 'GET',
				'callback' => array( $this, 'license_status' ),
			),
		);
	}

	public function get_translate_url( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->panel->handle_get_translate_url(
			(int) $request->get_param( 'post_id' ),
			(string) $request->get_param( 'target_lang' ),
			(int) $request->get_param( 'component_id' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function get_languages_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->panel->handle_get_languages_status(
			(int) $request->get_param( 'post_id' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function get_all_components( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->panel->handle_get_all_components(
			(int) $request->get_param( 'post_id' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function get_posts_by_type( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->panel->handle_get_posts_by_type(
			(string) $request->get_param( 'post_type' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function get_pill_statuses( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->panel->handle_get_pill_statuses(), 200 );
	}

	public function toggle_loop_preset( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->panel->handle_toggle_loop_preset(), 200 );
	}

	public function resync( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->panel->handle_resync(
			(int) $request->get_param( 'post_id' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function resync_all( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->panel->handle_resync_all(), 200 );
	}

	public function get_resync_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->panel->handle_get_resync_status(), 200 );
	}

	public function get_ai_settings( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->ai_handler->handle_get_settings(), 200 );
	}

	public function save_ai_settings( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->ai_handler->handle_save_settings( $request->get_json_params() );
		return new WP_REST_Response( $result, 200 );
	}

	public function test_ai_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->ai_handler->handle_test_connection();
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function ai_translate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->ai_handler->translate(
			(int) $request->get_param( 'post_id' ),
			(string) $request->get_param( 'target_lang' ),
			(int) $request->get_param( 'component_id' ),
			(bool) $request->get_param( 'force' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function ai_translate_all( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->ai_handler->translate_all(
			(int) $request->get_param( 'post_id' ),
			(int) $request->get_param( 'component_id' ),
			(bool) $request->get_param( 'force' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function ai_translate_loop( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->ai_handler->translate_loop(
			(string) $request->get_param( 'loop_id' ),
			(string) $request->get_param( 'target_lang' ),
			(bool) $request->get_param( 'force' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function ai_translate_loop_all( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->ai_handler->translate_loop_all(
			(string) $request->get_param( 'loop_id' ),
			(bool) $request->get_param( 'force' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function license_activate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( $this->is_license_rate_limited() ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Please wait a moment.', 'wpml-x-etch' ), array( 'status' => 429 ) );
		}

		$result = LicenseManager::get_instance()->activate(
			(string) $request->get_param( 'key' )
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public function license_deactivate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( $this->is_license_rate_limited() ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Please wait a moment.', 'wpml-x-etch' ), array( 'status' => 429 ) );
		}

		$result = LicenseManager::get_instance()->deactivate();

		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'deactivated' => true ), 200 );
	}

	public function license_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( LicenseManager::get_instance()->get_status(), 200 );
	}

	/**
	 * Simple rate limit for license endpoints: max 5 attempts per minute.
	 */
	private function is_license_rate_limited(): bool {
		$transient = 'zs_wxe_license_attempts';
		$attempts  = (int) get_transient( $transient );

		if ( $attempts >= 5 ) {
			return true;
		}

		set_transient( $transient, $attempts + 1, MINUTE_IN_SECONDS );
		return false;
	}
}
