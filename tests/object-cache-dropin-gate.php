<?php
/**
 * Standalone stale-loader and drop-in gate contract check.
 */

define( 'ABSPATH', __DIR__ . '/' );

function apcu_enabled() {
	return false;
}

function is_multisite() {
	return false;
}

function wp_suspend_cache_addition() {
	return false;
}

function apcm_expect( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL );
		exit( 1 );
	}
}

require_once __DIR__ . '/../includes/system/class-apcu.php';
require_once __DIR__ . '/../includes/api/object-class.php';

apcm_expect( method_exists( 'WP_Object_Cache', 'instance' ), 'stale loader receives plugin cache class' );

$cache = WP_Object_Cache::instance();
apcm_expect( true === $cache->set( 'stale-loader', 'coherent' ), 'stale loader set succeeds while APCu is unavailable' );
$success = null;
apcm_expect( 'coherent' === $cache->get( 'stale-loader', 'default', false, $success ) && true === $success, 'stale loader set is readable' );

require __DIR__ . '/../assets/object-cache.php';
apcm_expect( ! function_exists( 'wp_cache_init' ), 'guarded template defines no cache functions when APCu is unavailable' );

$stale_loader_init = static function () {
	global $wp_object_cache;
	if ( ! ( $wp_object_cache instanceof WP_Object_Cache ) ) {
		$wp_object_cache = WP_Object_Cache::instance();
	}
};

$wp_object_cache = null;
$stale_loader_init();
apcm_expect( $wp_object_cache instanceof WP_Object_Cache, 'pre-r2 loader can initialize the plugin cache class' );

fwrite( STDOUT, 'PASS: stale drop-in degrades without fatal and guarded template defines nothing' . PHP_EOL );
