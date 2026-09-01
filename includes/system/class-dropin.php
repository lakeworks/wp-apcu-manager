<?php
/**
 * Object-cache drop-in lifecycle.
 *
 * @package System
 * @since   4.6.2
 */

namespace APCuManager\System;

/**
 * Manage the APCu object-cache drop-in from explicit administrative paths.
 */
class Dropin {

	public const DEPLOYED = 'deployed';

	public const DEPLOYED_WITH_BACKUP = 'deployed_with_backup';

	public const REMOVED = 'removed';

	public const UNCHANGED = 'unchanged';

	public const FAILED = 'failed';

	private const MARKER = 'APCU_MANAGER_DROPIN';

	private const PLACEHOLDER = "'{{APCM_PLUGIN_DIR}}'";

	/**
	 * Apply an explicit administrator option change.
	 *
	 * @param bool $enabled Whether the managed drop-in should be deployed.
	 * @return string One of the lifecycle result constants.
	 */
	public static function apply_admin_change( $enabled ) {
		if ( ! is_admin() ) {
			return self::FAILED;
		}
		return self::sync( (bool) $enabled );
	}

	/**
	 * Refresh the managed drop-in after this plugin is upgraded.
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @param array $options Upgrader hook data.
	 * @return string One of the lifecycle result constants.
	 */
	public static function refresh_after_upgrade( $upgrader = null, $options = [] ) {
		if ( ! self::is_this_plugin_upgrade( $options ) ) {
			return self::UNCHANGED;
		}
		$result = self::sync( (bool) Option::network_get( 'earlyloading' ) );
		if ( self::FAILED === $result ) {
			\DecaLog\Engine::eventsLogger( APCM_SLUG )->error( 'Object-cache drop-in refresh failed after plugin upgrade.' );
		}
		return $result;
	}

	/**
	 * Verify that upgrader hook data targets this plugin.
	 *
	 * @param array $options Upgrader hook data.
	 * @return bool True for this plugin's update event.
	 */
	private static function is_this_plugin_upgrade( $options ) {
		if ( ! is_array( $options ) || ! isset( $options['action'], $options['type'] ) || 'update' !== $options['action'] || 'plugin' !== $options['type'] ) {
			return false;
		}
		$plugins = [];
		if ( isset( $options['plugin'] ) && is_string( $options['plugin'] ) ) {
			$plugins[] = $options['plugin'];
		}
		if ( isset( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			$plugins = array_merge( $plugins, $options['plugins'] );
		}
		return in_array( plugin_basename( APCM_PLUGIN_FILE ), $plugins, true );
	}

	/**
	 * Synchronize the drop-in with the desired state.
	 *
	 * @param bool $enabled Desired state.
	 * @return string One of the lifecycle result constants.
	 */
	private static function sync( $enabled ) {
		return $enabled ? self::deploy() : self::remove();
	}

	/**
	 * Generate and atomically deploy the managed drop-in.
	 *
	 * @return string One of the lifecycle result constants.
	 */
	private static function deploy() {
		$template = APCM_PLUGIN_DIR . 'assets/object-cache.php';
		$target   = WP_CONTENT_DIR . '/object-cache.php';
		if ( ! is_readable( $template ) ) {
			return self::FAILED;
		}
		$content = file_get_contents( $template );
		if ( ! is_string( $content ) || 1 !== substr_count( $content, self::PLACEHOLDER ) ) {
			return self::FAILED;
		}
		$plugin_dir = rtrim( APCM_PLUGIN_DIR, '/\\' );
		$content    = str_replace( self::PLACEHOLDER, var_export( $plugin_dir, true ), $content );
		$temp       = tempnam( WP_CONTENT_DIR, '.apcm-object-cache-' );
		if ( false === $temp ) {
			return self::FAILED;
		}
		$content_dir = rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' );
		if ( $content_dir !== wp_normalize_path( dirname( $temp ) ) ) {
			self::remove_temp( $temp );
			return self::FAILED;
		}
		$written = file_put_contents( $temp, $content, LOCK_EX );
		if ( strlen( $content ) !== $written || ! chmod( $temp, 0644 ) ) {
			self::remove_temp( $temp );
			return self::FAILED;
		}

		$backup = '';
		if ( file_exists( $target ) && ! self::is_managed( $target ) ) {
			$backup = WP_CONTENT_DIR . '/object-cache.php.bak-' . gmdate( 'YmdHis' );
			if ( file_exists( $backup ) || ! rename( $target, $backup ) ) {
				self::remove_temp( $temp );
				return self::FAILED;
			}
		}
		if ( ! rename( $temp, $target ) ) {
			self::remove_temp( $temp );
			if ( '' !== $backup && ! file_exists( $target ) ) {
				rename( $backup, $target );
			}
			return self::FAILED;
		}
		return '' === $backup ? self::DEPLOYED : self::DEPLOYED_WITH_BACKUP;
	}

	/**
	 * Remove only a drop-in owned by this plugin.
	 *
	 * @return string One of the lifecycle result constants.
	 */
	private static function remove() {
		$target = WP_CONTENT_DIR . '/object-cache.php';
		if ( ! file_exists( $target ) || ! self::is_managed( $target ) ) {
			return self::UNCHANGED;
		}
		return unlink( $target ) ? self::REMOVED : self::FAILED;
	}

	/**
	 * Verify drop-in ownership using the baked template marker.
	 *
	 * @param string $file Drop-in path.
	 * @return bool True for an APCu Manager drop-in.
	 */
	private static function is_managed( $file ) {
		$content = is_readable( $file ) ? file_get_contents( $file ) : false;
		return is_string( $content ) && false !== strpos( $content, self::MARKER );
	}

	/**
	 * Remove a temporary deployment file after a failed operation.
	 *
	 * @param string $file Temporary file path.
	 * @return void
	 */
	private static function remove_temp( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
}
