<?php
/**
 * Global functions for Perfops One features.
 *
 * @package @package PerfOpsOne
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   2.0.0
 */

global $wp_version;

use APCuManager\System\Plugin;

if ( ! function_exists( 'poo_switch_autoupdate_callback' ) ) {
	/**
	 * Ajax callback for autoupdate switching.
	 *
	 * @since    2.0.0
	 */
	function poo_switch_autoupdate_callback() {
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) || false === check_ajax_referer( 'poo-auto-update', 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$plugin = new Plugin( filter_input( INPUT_POST, 'plugin' ) );
		if ( ! current_user_can( 'update_plugins' ) || ! wp_is_auto_update_enabled_for_type( 'plugin' ) || ( is_multisite() && ! is_network_admin() ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( $plugin->switch_auto_update() ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( null, 500 );
		}
	}
	add_action( 'wp_ajax_poo_switch_autoupdate', 'poo_switch_autoupdate_callback' );
}
