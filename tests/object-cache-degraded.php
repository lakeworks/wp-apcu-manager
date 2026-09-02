<?php
/**
 * Standalone degraded-mode contract check.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'APCM_DROPIN_VERSION', 'test' );

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

$cache = WP_Object_Cache::instance();

apcm_expect( true === $cache->set( 'coherent', 'value' ), 'set succeeds' );
$success = null;
apcm_expect( 'value' === $cache->get( 'coherent', 'default', false, $success ) && true === $success, 'set is readable' );
apcm_expect( true === $cache->add( 'added', 'first' ), 'first add succeeds' );
apcm_expect( false === $cache->add( 'added', 'second' ), 'duplicate add fails' );
apcm_expect( true === $cache->set( 'counter', 5 ), 'counter set succeeds' );
apcm_expect( 7 === $cache->incr( 'counter', 2 ), 'increment returns integer value' );
apcm_expect( 4 === $cache->decr( 'counter', 3 ), 'decrement returns integer value' );
apcm_expect( false === $cache->replace( 'missing', 'value' ), 'replace rejects a missing key' );
apcm_expect( true === $cache->replace( 'coherent', 'replacement' ), 'replace updates an existing key' );
apcm_expect( true === $cache->delete( 'coherent' ), 'delete removes an existing key' );
apcm_expect( false === $cache->delete( 'coherent' ), 'delete rejects a missing key' );
apcm_expect( false === $cache->flush(), 'flush reports shared cache unavailable' );

fwrite( STDOUT, 'PASS: degraded object-cache contract' . PHP_EOL );
