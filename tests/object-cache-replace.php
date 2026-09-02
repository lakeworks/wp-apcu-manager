<?php
/**
 * Standalone persistent replace failure contract check.
 */

define( 'ABSPATH', __DIR__ . '/' );

function apcu_enabled() {
	return true;
}

function apcu_exists( $key ) {
	return true;
}

function apcu_store( $key, $value, $ttl = 0 ) {
	return false;
}

function apcu_fetch( $key, &$success = null ) {
	$success = true;
	return 'original';
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

$cache = WP_Object_Cache::instance();
apcm_expect( false === $cache->replace( 'replace-failure', 'replacement' ), 'replace reports failed APCu store' );
$success = null;
apcm_expect( 'original' === $cache->get( 'replace-failure', 'default', false, $success ) && true === $success, 'failed replace does not update local mirror' );

fwrite( STDOUT, 'PASS: failed persistent replace leaves the local mirror unchanged' . PHP_EOL );
