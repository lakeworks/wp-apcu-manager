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

	public const FOREIGN_LEFT = 'foreign_left';

	public const FAILED = 'failed';

	private const MARKER = 'APCU_MANAGER_DROPIN';

	private const PATH_PLACEHOLDER = "'{{APCM_PLUGIN_DIR}}'";

	private const VERSION_PLACEHOLDER = "'{{APCM_VERSION}}'";

	/**
	 * Apply an explicit administrator option change.
	 *
	 * @param bool $enabled Whether the managed drop-in should be deployed.
	 * @return string One of the lifecycle result constants.
	 */
	public static function apply_admin_change( $enabled ) {
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
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
	 * Deploy the managed drop-in from lifecycle paths outside wp-admin.
	 *
	 * @return string One of the lifecycle result constants.
	 */
	public static function deploy_managed() {
		return self::sync( true );
	}

	/**
	 * Remove the managed drop-in from lifecycle paths outside wp-admin.
	 *
	 * @return string One of the lifecycle result constants.
	 */
	public static function remove_managed() {
		return self::sync( false );
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
		if ( ! is_string( $content ) || 1 !== substr_count( $content, self::PATH_PLACEHOLDER ) || 1 !== substr_count( $content, self::VERSION_PLACEHOLDER ) ) {
			return self::FAILED;
		}
		$plugin_dir = rtrim( APCM_PLUGIN_DIR, '/\\' );
		$content    = str_replace(
			[ self::PATH_PLACEHOLDER, self::VERSION_PLACEHOLDER ],
			[ var_export( $plugin_dir, true ), var_export( APCM_VERSION, true ) ],
			$content
		);
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
			$backup_dir = self::prepare_backup_directory();
			if ( false === $backup_dir ) {
				self::remove_temp( $temp );
				return self::FAILED;
			}
			try {
				$backup = $backup_dir . '/object-cache.php.bak-' . bin2hex( random_bytes( 8 ) );
			} catch ( \Throwable $e ) {
				self::remove_temp( $temp );
				return self::FAILED;
			}
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
		if ( ! file_exists( $target ) ) {
			return self::UNCHANGED;
		}
		if ( ! self::is_managed( $target ) ) {
			return self::FOREIGN_LEFT;
		}
		return unlink( $target ) ? self::REMOVED : self::FAILED;
	}

	/**
	 * Create the private backup directory and its web-deny stubs.
	 *
	 * @return string|false Backup directory path, or false on failure.
	 */
	private static function prepare_backup_directory() {
		$directory = WP_CONTENT_DIR . '/apcm-backups';
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
			return false;
		}
		$stubs = [
			'index.php'  => "<?php\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <handlers accessPolicy=\"Read\" />\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n",
		];
		foreach ( $stubs as $name => $content ) {
			$file    = $directory . '/' . $name;
			$written = @file_put_contents( $file, $content, LOCK_EX );
			if ( strlen( $content ) !== $written ) {
				return false;
			}
		}
		return $directory;
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
