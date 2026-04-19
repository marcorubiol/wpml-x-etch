<?php
/**
 * WPML x Etch
 *
 * @package           WpmlXEtch
 * @author            Zerø Sense
 * @copyright         2024 Zerø Sense
 * @gplv2
 *
 * @wordpress-plugin
 * Plugin Name:       WPML x Etch
 * Description:       Integration bridge between Etch page builder and WPML Multilingual CMS.
 * Version:           1.0.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  etch, sitepress-multilingual-cms
 * Author:            Zerø Sense
 * Author URI:        https://zerosense.studio
 * Text Domain:       wpml-x-etch
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZS_WXE_PLUGIN_FILE', __FILE__ );
define( 'ZS_WXE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZS_WXE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZS_WXE_VERSION', '1.0.3' );

require_once ZS_WXE_PLUGIN_DIR . 'vendor/autoload.php';

$zs_wxe_plugin = new \WpmlXEtch\Core\Plugin();

register_activation_hook( __FILE__, array( $zs_wxe_plugin, 'on_activate' ) );
add_action( 'plugins_loaded', array( $zs_wxe_plugin, 'init' ), 20 );

