<?php
/**
 * Standalone degraded-mode contract check.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'APCM_DROPIN_VERSION', 'test' );

$apcm_apcu_enabled = true;
$apcm_shared_cache = [];
$apcm_fetch_calls  = 0;

function apcu_enabled() {
	return $GLOBALS['apcm_apcu_enabled'];
}

function apcu_store( $key, $value, $ttl = 0 ) {
	if ( ! apcu_enabled() ) {
		return false;
	}
	$GLOBALS['apcm_shared_cache'][ $key ] = $value;
	return true;
}

function apcu_fetch( $key, &$success = null ) {
	$GLOBALS['apcm_fetch_calls']++;
	$success = apcu_enabled() && array_key_exists( $key, $GLOBALS['apcm_shared_cache'] );
	return $success ? $GLOBALS['apcm_shared_cache'][ $key ] : false;
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

apcm_expect( true === $cache->set( 'before-flip', 'local-before-flip' ), 'persistent set succeeds before flip' );
apcm_expect( 1 === count( $apcm_shared_cache ), 'pre-flip set reaches the shared cache' );

$apcm_apcu_enabled = false;
foreach ( $apcm_shared_cache as $key => $value ) {
	$apcm_shared_cache[ $key ] = 'untrusted-shared-value';
}

$fetch_calls = $apcm_fetch_calls;
$success = null;
apcm_expect( 'local-before-flip' === $cache->get( 'before-flip', 'default', false, $success ) && true === $success, 'pre-flip local mirror remains coherent' );
apcm_expect( $fetch_calls === $apcm_fetch_calls, 'shared value is not trusted after flip' );
apcm_expect( true === $cache->set( 'after-flip', 'local-after-flip' ), 'degraded set succeeds after flip' );
$success = null;
apcm_expect( 'local-after-flip' === $cache->get( 'after-flip', 'default', false, $success ) && true === $success, 'post-flip set is readable' );
apcm_expect( false === $cache->add( 'before-flip', 'replacement' ), 'add rejects a key migrated during flip' );
apcm_expect( true === $cache->add( 'added', 'first' ), 'first add succeeds' );
apcm_expect( false === $cache->add( 'added', 'second' ), 'duplicate add fails' );
apcm_expect( true === $cache->set( 'counter', 5 ), 'counter set succeeds' );
apcm_expect( 7 === $cache->incr( 'counter', 2 ), 'increment returns integer value' );
apcm_expect( 4 === $cache->decr( 'counter', 3 ), 'decrement returns integer value' );
apcm_expect( false === $cache->incr( 'missing-counter', 1 ), 'increment rejects a missing key' );
apcm_expect( false === $cache->decr( 'missing-counter', 1 ), 'decrement rejects a missing key' );
apcm_expect( false === $cache->replace( 'missing', 'value' ), 'replace rejects a missing key' );
apcm_expect( true === $cache->replace( 'after-flip', 'replacement' ), 'replace updates an existing key' );
$success = null;
apcm_expect( 'replacement' === $cache->get( 'after-flip', 'default', false, $success ) && true === $success, 'replacement is readable' );
apcm_expect( true === $cache->delete( 'after-flip' ), 'delete removes an existing key' );
apcm_expect( false === $cache->delete( 'after-flip' ), 'delete rejects a missing key' );
apcm_expect( false === $cache->flush(), 'flush reports shared cache unavailable' );

fwrite( STDOUT, 'PASS: mid-request APCu flip preserves degraded object-cache contracts' . PHP_EOL );
