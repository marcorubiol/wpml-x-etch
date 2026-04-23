<?php
/**
 * GitHub Releases update checker.
 *
 * Hooks into WP's native update system so the plugin can be updated
 * from the Plugins screen even though it is not hosted on wordpress.org.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Core;

class UpdateChecker implements SubscriberInterface {

	private const GITHUB_REPO      = 'marcorubiol/wpml-x-etch';
	private const PLUGIN_URL       = 'https://wpml-x-etch.zerosense.studio/';
	private const CACHE_KEY        = 'zs_wxe_github_release';
	private const CACHE_KEY_README = 'zs_wxe_github_readme';
	private const CACHE_TTL        = 12 * HOUR_IN_SECONDS;

	private string $plugin_file;
	private string $plugin_slug;
	private string $plugin_basename;

	public function __construct( string $plugin_file ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_slug     = 'wpml-x-etch';
		$this->plugin_basename = plugin_basename( $plugin_file );
	}

	public static function getSubscribedEvents(): array {
		return array(
			array( 'pre_set_site_transient_update_plugins', 'check_for_update' ),
			array( 'plugins_api', 'plugin_info', 10, 3 ),
			array( 'plugin_action_links_wpml-x-etch/wpml-x-etch.php', 'add_check_update_link' ),
			array( 'admin_init', 'handle_check_update' ),
			array( 'upgrader_source_selection', 'fix_source_dir', 10, 4 ),
		);
	}

	/**
	 * Inject update data into the WP update transient.
	 *
	 * @param mixed $transient The update_plugins transient value.
	 * @return mixed
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'vV' );

		if ( version_compare( $remote_version, ZS_WXE_VERSION, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $remote_version,
				'package'     => $release['zip_url'],
				'url'         => self::PLUGIN_URL,
				'tested'      => '',
				'requires'    => '6.5',
				'requires_php' => '8.1',
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the "View details" modal in WP admin.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release['tag_name'], 'vV' );

		return (object) array(
			'name'          => 'WPML x Etch',
			'slug'          => $this->plugin_slug,
			'version'       => $remote_version,
			'author'        => '<a href="https://zerosense.studio">Zerø Sense</a>',
			'homepage'      => self::PLUGIN_URL,
			'requires'      => '6.5',
			'requires_php'  => '8.1',
			'download_link' => $release['zip_url'],
			'sections'      => array(
				'description' => 'Integration bridge between Etch page builder and WPML Multilingual CMS.',
				'changelog'   => $this->get_changelog_html( $release ),
			),
		);
	}

	/**
	 * Add a "Check for updates" action link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_check_update_link( $links ) {
		$url = wp_nonce_url(
			admin_url( 'plugins.php?zs_wxe_check_update=1' ),
			'zs_wxe_check_update'
		);
		$links['check_update'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'wpml-x-etch' ) . '</a>';
		return $links;
	}

	/**
	 * Handle the "Check for updates" action: clear cache and trigger WP update check.
	 */
	public function handle_check_update(): void {
		if ( empty( $_GET['zs_wxe_check_update'] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'zs_wxe_check_update' ) ) {
			return;
		}

		delete_transient( self::CACHE_KEY );
		delete_transient( self::CACHE_KEY_README );
		delete_site_transient( 'update_plugins' );
		wp_clean_plugins_cache( true );

		wp_safe_redirect( admin_url( 'plugins.php?zs_wxe_updated_check=1' ) );
		exit;
	}

	/**
	 * Rename extracted source folder to match plugin slug.
	 *
	 * GitHub's source zipball uses a folder like "owner-repo-sha/".
	 * WordPress needs "wpml-x-etch/" to match the installed plugin path.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . trailingslashit( $this->plugin_slug );
		if ( $source === $expected ) {
			return $source;
		}

		if ( rename( $source, $expected ) ) {
			return $expected;
		}

		return new \WP_Error( 'rename_failed', 'Could not rename plugin folder during update.' );
	}

	/**
	 * Build the changelog HTML for the "View details" modal.
	 *
	 * Prefers the remote readme.txt (uploaded as a release asset) so the
	 * modal shows the newest version's changelog before the user updates.
	 * Falls back to the locally installed readme.txt — which only contains
	 * the currently installed version's history — if the remote fetch
	 * fails or the release predates the asset convention.
	 */
	private function get_changelog_html( ?array $release = null ): string {
		$readme_url = $release['readme_url'] ?? '';
		if ( $readme_url ) {
			$remote = $this->fetch_remote_readme( $readme_url );
			if ( $remote ) {
				return $this->parse_changelog( $remote );
			}
		}

		$local = dirname( $this->plugin_file ) . '/readme.txt';
		if ( ! file_exists( $local ) ) {
			return '';
		}
		return $this->parse_changelog( (string) file_get_contents( $local ) );
	}

	/**
	 * Fetch the readme.txt asset from GitHub with transient caching.
	 * The asset URL is public even for private repos because GitHub
	 * issues unauthenticated download URLs for release assets.
	 */
	private function fetch_remote_readme( string $url ): string {
		$cached = get_transient( self::CACHE_KEY_README );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return '';
		}

		set_transient( self::CACHE_KEY_README, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * Parse the == Changelog == section from raw readme.txt contents into HTML.
	 */
	private function parse_changelog( string $contents ): string {
		$pos = strpos( $contents, '== Changelog ==' );
		if ( false === $pos ) {
			return '';
		}

		$changelog = substr( $contents, $pos + strlen( '== Changelog ==' ) );

		// Stop at next == Section == if any.
		$next_section = strpos( $changelog, "\n==" );
		if ( false !== $next_section ) {
			$changelog = substr( $changelog, 0, $next_section );
		}

		// Convert readme.txt format to HTML.
		$changelog = trim( $changelog );
		$changelog = esc_html( $changelog );
		$changelog = preg_replace( '/^= (.+?) =/m', '<h4>$1</h4>', $changelog );
		$changelog = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $changelog );
		$changelog = preg_replace( '#(<li>.*</li>)#s', '<ul>$0</ul>', $changelog );
		// Clean up duplicate nested <ul> tags.
		$changelog = preg_replace( '#</ul>\s*<ul>#', '', $changelog );

		return $changelog;
	}

	/**
	 * Fetch the latest release from GitHub, with transient caching.
	 *
	 * @return array{tag_name: string, zip_url: string, body: string}|null
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url      = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['tag_name'] ) ) {
			return null;
		}

		// Look for zip and readme assets in the release.
		$zip_url    = '';
		$readme_url = '';
		if ( ! empty( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				$name = $asset['name'] ?? '';
				if ( '' === $zip_url && str_ends_with( $name, '.zip' ) ) {
					$zip_url = $asset['browser_download_url'];
				} elseif ( '' === $readme_url && 'readme.txt' === $name ) {
					$readme_url = $asset['browser_download_url'];
				}
			}
		}

		// No zip asset yet (workflow may still be building). Don't cache.
		if ( empty( $zip_url ) ) {
			return null;
		}

		$result = array(
			'tag_name'   => $data['tag_name'],
			'zip_url'    => $zip_url,
			'readme_url' => $readme_url,
			'body'       => $data['body'] ?? '',
		);

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		return $result;
	}
}
