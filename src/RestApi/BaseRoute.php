<?php
/**
 * Abstract base route for REST API endpoints.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\RestApi;

/**
 * Base route class providing common permission handling.
 */
abstract class BaseRoute {

	/**
	 * REST API namespace.
	 */
	protected const NAMESPACE = 'wpml-x-etch/v1';

	/**
	 * Get the route definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract protected function get_routes(): array;

	/**
	 * Register all routes with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->get_routes() as $route_def ) {
			register_rest_route(
				self::NAMESPACE,
				$route_def['route'],
				array(
					'methods'             => $route_def['methods'],
					'callback'            => $route_def['callback'],
					'permission_callback' => $route_def['permission_callback'] ?? array( $this, 'has_access' ),
					'args'                => $route_def['args'] ?? array(),
				)
			);
		}
	}

	/**
	 * Default permission check — filterable capability.
	 *
	 * @return bool
	 */
	public function has_access(): bool {
		$can = current_user_can( 'manage_options' ) || current_user_can( 'translate' );
		return (bool) apply_filters( 'zs_wxe_user_can_translate', $can );
	}
}
