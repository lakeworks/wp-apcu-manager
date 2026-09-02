<?php
/**
 * Main plugin file.
 *
 * @package Bootstrap
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       APCu Manager
 * Plugin URI:        https://perfops.one/apcu-manager
 * Update URI:        false
 * Description:       APCu statistics and management right in the WordPress admin dashboard.
 * Version:           4.6.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Pierre Lannoy / PerfOps One
 * Author URI:        https://perfops.one
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Network:           true
 * Text Domain:       apcu-manager
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'APCM_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/system/class-option.php';
require_once __DIR__ . '/includes/system/class-environment.php';
require_once __DIR__ . '/autoload.php';

/**
 * Display the controlled failure for an incomplete vendored-library bundle.
 *
 * @since 4.6.2
 */
function apcm_missing_vendor_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'APCu Manager was deactivated because a required bundled library is missing. Reinstall the plugin from a trusted source.', 'apcu-manager' ) . '</p></div>';
}

/**
 * Self-deactivate when a required vendored library is missing.
 *
 * @since 4.6.2
 */
function apcm_deactivate_for_missing_vendor() {
	if ( ! is_admin() || ! function_exists( 'current_user_can' ) ) {
		return;
	}
	$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $capability ) ) {
		return;
	}
	\APCuManager\System\Dropin::remove_managed();
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( plugin_basename( __FILE__ ), true, is_multisite() && is_network_admin() );
}

/**
 * Stop activation cleanly when the vendored-library bundle is incomplete.
 *
 * @since 4.6.2
 */
function apcm_missing_vendor_activation_failure() {
	apcm_deactivate_for_missing_vendor();
	wp_die(
		esc_html__( 'APCu Manager could not be activated because a required bundled library is missing. Reinstall the plugin from a trusted source.', 'apcu-manager' ),
		esc_html__( 'APCu Manager activation failed', 'apcu-manager' ),
		[ 'back_link' => true ]
	);
}

/**
 * Verify the entry points for every required vendored library.
 *
 * @return bool True when all required library entry points exist.
 * @since 4.6.2
 */
function apcm_has_required_vendor_libraries() {
	$required = [
		__DIR__ . '/includes/libraries/class-libraries.php',
		__DIR__ . '/includes/libraries/autoload.php',
		__DIR__ . '/includes/libraries/decalog-sdk/Engine.php',
		__DIR__ . '/includes/libraries/decalog-sdk/EventsLogger.php',
		__DIR__ . '/includes/libraries/decalog-sdk/MetricsLogger.php',
		__DIR__ . '/includes/libraries/decalog-sdk/TracesLogger.php',
		__DIR__ . '/includes/libraries/feather/Icons.php',
		__DIR__ . '/includes/libraries/kint/apcm_init.php',
		__DIR__ . '/includes/libraries/markdown/Parser.php',
		__DIR__ . '/includes/libraries/psr/http-client/ClientInterface.php',
		__DIR__ . '/includes/libraries/psr/http-message/MessageInterface.php',
		__DIR__ . '/includes/libraries/psr/log-v1/LoggerInterface.php',
		__DIR__ . '/includes/libraries/psr/log-v3/LoggerInterface.php',
		__DIR__ . '/includes/libraries/spyc/Spyc.php',
		__DIR__ . '/perfopsone/init.php',
	];
	foreach ( $required as $file ) {
		if ( ! is_readable( $file ) ) {
			return false;
		}
	}
	return true;
}

if ( is_admin() && ! apcm_has_required_vendor_libraries() ) {
	add_action( 'admin_notices', 'apcm_missing_vendor_notice' );
	add_action( 'network_admin_notices', 'apcm_missing_vendor_notice' );
	register_activation_hook( __FILE__, 'apcm_missing_vendor_activation_failure' );
	add_action( 'admin_init', 'apcm_deactivate_for_missing_vendor' );
	return;
}

$apcm_libraries_loaded = @include_once __DIR__ . '/includes/libraries/class-libraries.php';
$apcm_autoload_loaded  = @include_once __DIR__ . '/includes/libraries/autoload.php';
if ( false === $apcm_libraries_loaded || false === $apcm_autoload_loaded || ! class_exists( 'DecaLog\Engine' ) ) {
	return;
}
require_once __DIR__ . '/includes/features/class-wpcli.php';

/**
 * The code that runs during plugin activation.
 *
 * @since 1.0.0
 */
function apcm_activate() {
	APCuManager\Plugin\Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 *
 * @since 1.0.0
 */
function apcm_deactivate() {
	APCuManager\Plugin\Deactivator::deactivate();
}

/**
 * The code that runs during plugin uninstallation.
 *
 * @since 1.0.0
 */
function apcm_uninstall() {
	APCuManager\Plugin\Uninstaller::uninstall();
}

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function apcm_run() {
	\DecaLog\Engine::initPlugin( APCM_SLUG, APCM_PRODUCT_NAME, APCM_VERSION, \APCuManager\Plugin\Core::get_base64_logo() );
	if ( wp_using_ext_object_cache() ) {
		global $wp_object_cache;
		if ( method_exists( $wp_object_cache, 'get_manager_id' ) && 'apcm' === $wp_object_cache::get_manager_id() ) {
			if ( method_exists( $wp_object_cache, 'set_events_logger' ) ) {
				$wp_object_cache::set_events_logger( \DecaLog\Engine::eventsLogger( APCM_SLUG ) );
			}
			if ( method_exists( $wp_object_cache, 'set_traces_logger' ) ) {
				$wp_object_cache::set_traces_logger( \DecaLog\Engine::tracesLogger( APCM_SLUG ) );
			}
			if ( method_exists( $wp_object_cache, 'set_metrics_logger' ) ) {
				$wp_object_cache::set_metrics_logger( \DecaLog\Engine::metricsLogger( APCM_SLUG ) );
			}
		}
	}
	\APCuManager\System\Cache::init();
	$plugin = new APCuManager\Plugin\Core();
	$plugin->run();
}

register_activation_hook( __FILE__, 'apcm_activate' );
register_deactivation_hook( __FILE__, 'apcm_deactivate' );
register_uninstall_hook( __FILE__, 'apcm_uninstall' );
apcm_run();
