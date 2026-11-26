<?php
/**
 * Standalone check: a version change never empties the object cache, and autopurge_update defaults to off.
 *
 * APCu::reset() deletes every key under APCU_CACHE_PREFIX, the object cache's own prefix, so calling it on an
 * update emptied the whole object cache. Run: php tests/updater-no-reset.php (exit 0 = pass).
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'APCM_PRODUCT_NAME', 'APCu Manager' );
	define( 'APCM_PRODUCT_ABBREVIATION', 'apcm' );
	define( 'APCM_SLUG', 'apcu-manager' );
	define( 'APCM_VERSION', '4.7.0' );
	define( 'APCM_PRODUCT_URL', 'https://example.com/apcu-manager' );

	$GLOBALS['apcm_site_options'] = [];

	function get_site_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['apcm_site_options'] ) ? $GLOBALS['apcm_site_options'][ $name ] : $default;
	}

	function update_site_option( $name, $value ) {
		$GLOBALS['apcm_site_options'][ $name ] = $value;
		return true;
	}

	function esc_html__( $text, $domain = null ) {
		return $text;
	}

	function __( $text, $domain = null ) {
		return $text;
	}

	function admin_url( $path = '' ) {
		return 'https://example.com/wp-admin/' . $path;
	}

	function apcm_expect( $condition, $label ) {
		if ( ! $condition ) {
			fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL );
			exit( 1 );
		}
	}
}

namespace DecaLog {
	class Engine {
		public static function eventsLogger( $slug ) {
			return new class() {
				public function notice( $message ) {}
			};
		}
	}
}

namespace APCuManager\System {
	class Nag {
		public static function add( $id, $type, $message ) {}
	}

	class APCu {
		public static $resets = 0;

		public static function reset() {
			++self::$resets;
			return true;
		}
	}
}

namespace {
	require_once __DIR__ . '/../includes/system/class-option.php';
	require_once __DIR__ . '/../includes/plugin/class-updater.php';

	use APCuManager\System\APCu;
	use APCuManager\System\Option;
	use APCuManager\Plugin\Updater;

	Option::init();
	apcm_expect( false === Option::network_get( 'autopurge_update' ), 'autopurge_update defaults to off' );
	update_site_option( 'apcm_autopurge_update', true );
	apcm_expect( true === Option::network_get( 'autopurge_update' ), 'an operator who turns autopurge_update on gets it' );
	unset( $GLOBALS['apcm_site_options']['apcm_autopurge_update'] );

	update_site_option( 'apcm_version', '4.6.1' );
	new Updater();
	apcm_expect( 0 === APCu::$resets, 'an update (4.6.1 -> 4.7.0) does not reset APCu' );
	apcm_expect( '4.7.0' === get_site_option( 'apcm_version' ), 'the update records the new version' );

	new Updater();
	apcm_expect( 0 === APCu::$resets, 'a request on an unchanged version does not reset APCu' );

	$GLOBALS['apcm_site_options'] = [];
	new Updater();
	apcm_expect( 1 === APCu::$resets, 'a fresh install still resets once' );

	echo 'PASS updater-no-reset' . PHP_EOL;
}
