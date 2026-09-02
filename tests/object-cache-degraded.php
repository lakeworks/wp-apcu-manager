<?php
/**
 * Standalone degraded-mode contract check.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'APCM_DROPIN_VERSION', 'test' );
define( 'APCU_DEGRADED_MAX_ENTRIES', 5 );

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

$cache->add_non_persistent_groups( 'runtime' );
for ( $i = 1; $i <= APCU_DEGRADED_MAX_ENTRIES + 1; $i++ ) {
	apcm_expect( true === $cache->set( 'healthy-' . $i, $i, 'runtime' ), 'healthy non-persistent groups are not capped' );
}
$success = null;
apcm_expect( 1 === $cache->get( 'healthy-1', 'runtime', false, $success ) && true === $success, 'healthy non-persistent oldest entry remains readable past degraded cap' );

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
apcm_expect( true === $cache->set( 'cap-fill-1', 'one' ), 'degraded store accepts entries below its cap' );
apcm_expect( true === $cache->set( 'cap-fill-2', 'two' ), 'degraded store accepts its final entry' );
apcm_expect( true === $cache->set( 'cap-overflow', 'newest' ), 'degraded store accepts a new key past its cap' );
$success = null;
apcm_expect( 'newest' === $cache->get( 'cap-overflow', 'default', false, $success ) && true === $success, 'newest degraded key is readable past the cap' );
$success = null;
apcm_expect( false === $cache->get( 'before-flip', 'default', false, $success ) && false === $success, 'oldest degraded key is evicted first' );
apcm_expect( true === $cache->add( 'cap-added', 'added-past-cap' ), 'degraded add succeeds for a new key past the cap' );
$success = null;
apcm_expect( 'added-past-cap' === $cache->get( 'cap-added', 'default', false, $success ) && true === $success, 'new degraded add is readable past the cap' );
apcm_expect( true === $cache->set( 'counter', 9 ), 'degraded store updates an existing key at its cap' );
$success = null;
apcm_expect( 9 === $cache->get( 'counter', 'default', false, $success ) && true === $success, 'existing degraded key keeps overwrite semantics at cap' );
apcm_expect( false === $cache->flush(), 'flush reports shared cache unavailable' );

fwrite( STDOUT, 'PASS: mid-request APCu flip preserves degraded object-cache contracts' . PHP_EOL );
