<?php
/**
 * APCu Manager schema
 *
 * Handles all schema operations.
 *
 * @package Features
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */

namespace APCuManager\Plugin\Feature;

use APCuManager\System\APCu;

use APCuManager\System\Option;
use APCuManager\System\Database;

use APCuManager\System\Cache;

/**
 * Define the schema functionality.
 *
 * Handles all schema operations.
 *
 * @package Features
 * @author  Pierre Lannoy <https://pierre.lannoy.fr/>.
 * @since   1.0.0
 */
class Schema {

	/**
	 * Current analytics schema version.
	 *
	 * @since 4.6.2
	 * @var string
	 */
	private const VERSION = '2';

	/**
	 * Statistics table name.
	 *
	 * @since  1.0.0
	 * @var    string    $statistics    The statistics table name.
	 */
	private static $statistics = APCM_PRODUCT_ABBREVIATION . '_statistics';

	/**
	 * Details table name.
	 *
	 * @since  1.0.0
	 * @var    string    $details    The details table name.
	 */
	private static $details = APCM_PRODUCT_ABBREVIATION . '_details';

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
	}

	/**
	 * Effectively write a record in the database.
	 *
	 * @param   array  $record    The record to write.
	 * @param   string $table     The table to write in.
	 * @since    1.0.0
	 **/
	private function write_to_database( $record, $table ) {
		$field_insert = [];
		$value_insert = [];
		$value_update = [];
		foreach ( $record as $k => $v ) {
			$field_insert[] = '`' . $k . '`';
			$value_insert[] = "'" . $v . "'";
			$value_update[] = '`' . $k . '`=' . "'" . $v . "'";
		}
		if ( count( $field_insert ) > 0 ) {
			global $wpdb;
			$sql  = 'INSERT INTO `' . $wpdb->base_prefix . $table . '` ';
			$sql .= '(' . implode( ',', $field_insert ) . ') ';
			$sql .= 'VALUES (' . implode( ',', $value_insert ) . ') ';
			$sql .= 'ON DUPLICATE KEY UPDATE ' . implode( ',', $value_update ) . ';';
			// phpcs:ignore
			$wpdb->query( $sql );
		}
	}

	/**
	 * Write the stats and details in the database.
	 *
	 * @param   array $stat     The stat to write.
	 * @param   array $details   Optional. The detail to write.
	 * @since    1.0.0
	 **/
	public function write_statistics_record_to_database( $stat, $details = [] ) {
		$this->write_to_database( $stat, self::$statistics );
		foreach ( $details as $detail ) {
			$this->write_to_database( $detail, self::$details );
		}
		$this->purge();
	}

	/**
	 * Initialize the schema.
	 *
	 * @since    1.0.0
	 */
	public function initialize() {
		if ( $this->run_migration() ) {
			\DecaLog\Engine::eventsLogger( APCM_SLUG )->info( 'Schema installed.' );
		}
	}

	/**
	 * Finalize the schema.
	 *
	 * @since    1.0.0
	 */
	public function finalize() {
		global $wpdb;
		$sql = 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . self::$statistics;
		// phpcs:ignore
		$wpdb->query( $sql );
		\DecaLog\Engine::eventsLogger( APCM_SLUG )->debug( sprintf( 'Table "%s" removed.', $wpdb->base_prefix . self::$statistics ) );
		$sql = 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . self::$details;
		// phpcs:ignore
		$wpdb->query( $sql );
		\DecaLog\Engine::eventsLogger( APCM_SLUG )->debug( sprintf( 'Table "%s" removed.', $wpdb->base_prefix . self::$details ) );
		\DecaLog\Engine::eventsLogger( APCM_SLUG )->debug( 'Schema destroyed.' );
	}

	/**
	 * Update the schema.
	 *
	 * @since    1.0.0
	 */
	public function update() {
		return self::maybe_migrate();
	}

	/**
	 * Run a pending schema migration from an admin or upgrader context.
	 *
	 * @param mixed $upgrader Optional upgrader instance.
	 * @param array $options Optional upgrader hook data.
	 * @return bool True when the schema is current, false otherwise.
	 * @since 4.6.2
	 */
	public static function maybe_migrate( $upgrader = null, $options = [] ) {
		$is_upgrade = is_array( $options ) && isset( $options['action'], $options['type'] ) && in_array( $options['action'], [ 'install', 'update' ], true ) && 'plugin' === $options['type'];
		if ( ! is_admin() && ! $is_upgrade ) {
			return false;
		}
		if ( version_compare( (string) Option::network_get( 'schema_version' ), self::VERSION, '>=' ) ) {
			return true;
		}
		$schema = new self();
		return $schema->run_migration();
	}

	/**
	 * Create or migrate the analytics schema.
	 *
	 * @return bool True when the schema version was stored, false otherwise.
	 * @since 4.6.2
	 */
	private function run_migration() {
		try {
			$this->create_tables();
			if ( ! $this->migrate_existing_tables() ) {
				return false;
			}
			Option::network_set( 'schema_version', self::VERSION );
			if ( self::VERSION !== (string) Option::network_get( 'schema_version' ) ) {
				\DecaLog\Engine::eventsLogger( APCM_SLUG )->alert( 'Schema migration failed while storing its version.' );
				return false;
			}
			\DecaLog\Engine::eventsLogger( APCM_SLUG )->info( 'Schema updated.' );
			return true;
		} catch ( \Throwable $e ) {
			\DecaLog\Engine::eventsLogger( APCM_SLUG )->alert( sprintf( 'Schema migration failed with %s at %s:%d.', get_class( $e ), basename( $e->getFile() ), $e->getLine() ) );
			return false;
		}
	}

	/**
	 * Purge old records.
	 *
	 * @since    1.0.0
	 */
	private function purge() {
		$days = (int) Option::network_get( 'history' );
		if ( ! is_numeric( $days ) || 21 > $days ) {
			$days = 21;
			Option::network_set( 'history', $days );
		}
		$database = new Database();
		$count    = $database->purge( self::$statistics, 'timestamp', 24 * $days );
		$count    = $count + $database->purge( self::$details, 'timestamp', 24 * $days );
		if ( 0 === $count ) {
			\DecaLog\Engine::eventsLogger( APCM_SLUG )->debug( 'No old records to delete.' );
		} elseif ( 1 === $count ) {
			\DecaLog\Engine::eventsLogger( APCM_SLUG )->debug( '1 old record deleted.' );
			Cache::delete_global( 'data/oldestdate' );
		} else {
			\DecaLog\Engine::eventsLogger( APCM_SLUG )->debug( sprintf( '%1$s old records deleted.', $count ) );
			Cache::delete_global( 'data/oldestdate' );
		}

	}

	/**
	 * Create the table.
	 *
	 * @since    1.0.0
	 */
	private function create_tables() {
		global $wpdb;
		$charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		$sql             = 'CREATE TABLE IF NOT EXISTS ' . $wpdb->base_prefix . self::$statistics;
		$sql            .= ' (`timestamp` datetime NOT NULL,';
		$sql            .= " `delta` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `status` enum('" . implode( "','", APCu::$status ) . "') NOT NULL DEFAULT 'disabled',";
		$sql            .= " `mem_total` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `mem_used` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `slot_total` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `slot_used` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `frag_small` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `frag_big` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `frag_count` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `hit` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `miss` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `ins` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " PRIMARY KEY (`timestamp`)";
		$sql            .= ") $charset_collate;";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are plugin-owned constants.
		if ( false === $wpdb->query( $sql ) ) {
			throw new \RuntimeException( 'Statistics table creation failed.' );
		}
		$charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		$sql             = 'CREATE TABLE IF NOT EXISTS ' . $wpdb->base_prefix . self::$details;
		$sql            .= ' (`timestamp` datetime NOT NULL,';
		$sql            .= " `id` varchar(100) NOT NULL DEFAULT '-',";
		$sql            .= " `items` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= " `size` int(11) UNSIGNED NOT NULL DEFAULT '0',";
		$sql            .= ' PRIMARY KEY (`timestamp`, `id`)';
		$sql            .= ") $charset_collate;";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are plugin-owned constants.
		if ( false === $wpdb->query( $sql ) ) {
			throw new \RuntimeException( 'Details table creation failed.' );
		}
	}

	/**
	 * Migrate existing analytics tables to the current schema.
	 *
	 * @return bool True on success, false on a checked database failure.
	 * @since 4.6.2
	 */
	private function migrate_existing_tables() {
		global $wpdb;
		$statistics = $wpdb->base_prefix . self::$statistics;
		$details    = $wpdb->base_prefix . self::$details;
		$sql        = 'ALTER TABLE `' . $statistics . '` MODIFY `timestamp` datetime NOT NULL';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a plugin-owned constant.
		if ( false === $wpdb->query( $sql ) ) {
			return $this->migration_failed( 'statistics timestamp migration' );
		}
		$sql = 'ALTER TABLE `' . $details . '` MODIFY `timestamp` datetime NOT NULL';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a plugin-owned constant.
		if ( false === $wpdb->query( $sql ) ) {
			return $this->migration_failed( 'details timestamp migration' );
		}
		return $this->migrate_details_primary_key( $details );
	}

	/**
	 * Add the composite details primary key after resolving duplicate rows.
	 *
	 * @param string $table Fully prefixed details table name.
	 * @return bool True on success, false on a checked database failure.
	 * @since 4.6.2
	 */
	private function migrate_details_primary_key( $table ) {
		global $wpdb;
		$sql = 'SHOW KEYS FROM `' . $table . '`';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a plugin-owned constant.
		$indexes = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $indexes ) ) {
			return $this->migration_failed( 'details index inspection' );
		}
		$primary_columns = [];
		$has_legacy_key  = false;
		foreach ( $indexes as $index ) {
			if ( isset( $index['Key_name'] ) && 'PRIMARY' === $index['Key_name'] && isset( $index['Seq_in_index'], $index['Column_name'] ) ) {
				$primary_columns[ (int) $index['Seq_in_index'] ] = $index['Column_name'];
			}
			if ( isset( $index['Key_name'] ) && 'u_stat' === $index['Key_name'] ) {
				$has_legacy_key = true;
			}
		}
		ksort( $primary_columns );
		$primary_columns = array_values( $primary_columns );
		if ( [ 'timestamp', 'id' ] === $primary_columns ) {
			if ( $has_legacy_key ) {
				$sql = 'ALTER TABLE `' . $table . '` DROP INDEX `u_stat`';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table and index names are fixed.
				if ( false === $wpdb->query( $sql ) ) {
					return $this->migration_failed( 'legacy details index removal' );
				}
			}
			return true;
		}

		$sql = 'SELECT `timestamp`, `id`, COUNT(*) AS `duplicate_count` FROM `' . $table . '` GROUP BY `timestamp`, `id` HAVING COUNT(*) > 1';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a plugin-owned constant.
		$duplicates = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $duplicates ) ) {
			return $this->migration_failed( 'details duplicate inspection' );
		}
		foreach ( $duplicates as $duplicate ) {
			$delete_count = (int) $duplicate['duplicate_count'] - 1;
			if ( 0 < $delete_count ) {
				$sql = $wpdb->prepare( 'DELETE FROM `' . $table . '` WHERE `timestamp` = %s AND `id` = %s LIMIT %d', $duplicate['timestamp'], $duplicate['id'], $delete_count );
				if ( ! is_string( $sql ) ) {
					return $this->migration_failed( 'details duplicate query preparation' );
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- values are prepared above.
				$deleted = $wpdb->query( $sql );
				if ( false === $deleted || $delete_count !== (int) $deleted ) {
					return $this->migration_failed( 'details duplicate resolution' );
				}
			}
		}

		$clauses = [];
		if ( [] !== $primary_columns ) {
			$clauses[] = 'DROP PRIMARY KEY';
		}
		if ( $has_legacy_key ) {
			$clauses[] = 'DROP INDEX `u_stat`';
		}
		$clauses[] = 'ADD PRIMARY KEY (`timestamp`, `id`)';
		$sql       = 'ALTER TABLE `' . $table . '` ' . implode( ', ', $clauses );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table and index names are fixed.
		if ( false === $wpdb->query( $sql ) ) {
			return $this->migration_failed( 'details primary key migration' );
		}
		return true;
	}

	/**
	 * Log a non-fatal migration failure without database contents or errors.
	 *
	 * @param string $stage Static migration stage identifier.
	 * @return false
	 * @since 4.6.2
	 */
	private function migration_failed( $stage ) {
		\DecaLog\Engine::eventsLogger( APCM_SLUG )->alert( sprintf( 'Schema migration aborted during %s.', $stage ) );
		return false;
	}

	/**
	 * Get an empty record.
	 *
	 * @return  array   An empty, ready to use, record.
	 * @since    1.0.0
	 */
	public function init_record() {
		$datetime = new \DateTime();
		$record   = [
			'timestamp'  => $datetime->format( 'Y-m-d H:i:s' ),
			'delta'      => 0,
			'status'     => 'disabled',
			'mem_total'  => 0,
			'mem_used'   => 0,
			'slot_total' => 0,
			'slot_used'  => 0,
			'frag_small' => 0,
			'frag_big'   => 0,
			'frag_count' => 0,
			'hit'        => 0,
			'miss'       => 0,
			'ins'        => 0,
		];
		return $record;
	}

	/**
	 * Get an empty detail.
	 *
	 * @return  array   An empty, ready to use, detail.
	 * @since    1.0.0
	 */
	public function init_detail() {
		$datetime = new \DateTime();
		$detail   = [
			'timestamp' => $datetime->format( 'Y-m-d H:i:s' ),
			'id'        => 'id',
			'items'     => 0,
			'size'      => 0,
		];
		return $detail;
	}

	/**
	 * Get "where" clause of a query.
	 *
	 * @param array $filters Optional. An array of filters.
	 * @return string The "where" clause.
	 * @since 1.0.0
	 */
	private static function get_where_clause( $filters = [] ) {
		$result = '';
		if ( 0 < count( $filters ) ) {
			$w = [];
			foreach ( $filters as $key => $filter ) {
				if ( is_array( $filter ) ) {
					$w[] = '`' . $key . '` IN (' . implode( ',', $filter ) . ')';
				} else {
					$w[] = '`' . $key . '`="' . $filter . '"';
				}
			}
			$result = 'WHERE (' . implode( ' AND ', $w ) . ')';
		}
		return $result;
	}

	/**
	 * Get the oldest date.
	 *
	 * @return  string   The oldest timestamp in the statistics table.
	 * @since    1.0.0
	 */
	public static function get_oldest_date() {
		$result = Cache::get_global( 'data/oldestdate' );
		if ( $result ) {
			return $result;
		}
		global $wpdb;
		$sql = 'SELECT * FROM ' . $wpdb->base_prefix . self::$statistics . ' ORDER BY `timestamp` ASC LIMIT 1';
		// phpcs:ignore
		$result = $wpdb->get_results( $sql, ARRAY_A );
		if ( is_array( $result ) && 0 < count( $result ) && array_key_exists( 'timestamp', $result[0] ) ) {
			Cache::set_global( 'data/oldestdate', $result[0]['timestamp'], 'infinite' );
			return $result[0]['timestamp'];
		}
		return '';
	}

	/**
	 * Get the standard KPIs.
	 *
	 * @param   array   $filter      The filter of the query.
	 * @param   boolean $cache       Has the query to be cached.
	 * @param   string  $extra_field Optional. The extra field to filter.
	 * @param   array   $extras      Optional. The extra values to match.
	 * @param   boolean $not         Optional. Exclude extra filter.
	 * @return  array   The standard KPIs.
	 * @since    1.0.0
	 */
	public static function get_std_kpi( $filter, $cache = true, $extra_field = '', $extras = [], $not = false ) {
		// phpcs:ignore
		$id = Cache::id( __FUNCTION__ . serialize( $filter ) . $extra_field . serialize( $extras ) . ( $not ? 'no' : 'yes') );
		$result = Cache::get_global( $id );
		if ( $result ) {
			return $result;
		}
		$where_extra = '';
		if ( 0 < count( $extras ) && '' !== $extra_field ) {
			$where_extra = ' AND ' . $extra_field . ( $not ? ' NOT' : '' ) . " IN ( '" . implode( "', '", $extras ) . "' )";
		}
		global $wpdb;
		$sql = 'SELECT count(*) as records, avg(delta) as avg_delta,sum(hit) as sum_hit,avg(hit) as avg_hit,avg(miss) as avg_miss, avg(ins) as avg_ins, avg(mem_total) as avg_mem_total, avg(mem_used) as avg_mem_used,  avg(slot_total) as avg_slot_total, avg(slot_used) as avg_slot_used, min(slot_used) as min_slot_used, max(slot_used) as max_slot_used,avg(frag_small) as avg_frag_small, avg(frag_big) as avg_frag_big, avg(frag_count) as avg_frag_count, min(frag_count) as min_frag_count, max(frag_count) as max_frag_count FROM ' . $wpdb->base_prefix . self::$statistics . ' WHERE (' . implode( ' AND ', $filter ) . ') ' . $where_extra;
		// phpcs:ignore
		$result = $wpdb->get_results( $sql, ARRAY_A );
		if ( is_array( $result ) && 1 === count( $result ) ) {
			Cache::set_global( $id, $result[0], $cache ? 'infinite' : 'ephemeral' );
			return $result[0];
		}
		return [];
	}

	/**
	 * Get a time series.
	 *
	 * @param   array   $filter      The filter of the query.
	 * @param   boolean $cache       Has the query to be cached.
	 * @param   string  $extra_field Optional. The extra field to filter.
	 * @param   array   $extras      Optional. The extra values to match.
	 * @param   boolean $not         Optional. Exclude extra filter.
	 * @param   integer $limit       Optional. The number of results to return.
	 * @return  array   The time series.
	 * @since    1.0.0
	 */
	public static function get_time_series( $filter, $cache = true, $extra_field = '', $extras = [], $not = false, $limit = 0 ) {
		$data   = self::get_list( $filter, $cache, $extra_field, $extras, $not, 'ORDER BY timestamp ASC', $limit );
		$result = [];
		foreach ( $data as $datum ) {
			$result[ $datum['timestamp'] ] = $datum;
		}
		return $result;
	}

	/**
	 * Get the standard KPIs.
	 *
	 * @param   array   $filter      The filter of the query.
	 * @param   boolean $cache       Has the query to be cached.
	 * @param   string  $extra_field Optional. The extra field to filter.
	 * @param   array   $extras      Optional. The extra values to match.
	 * @param   boolean $not         Optional. Exclude extra filter.
	 * @param   string  $order       Optional. The sort order of results.
	 * @param   integer $limit       Optional. The number of results to return.
	 * @return  array   The standard KPIs.
	 * @since    1.0.0
	 */
	public static function get_list( $filter, $cache = true, $extra_field = '', $extras = [], $not = false, $order = '', $limit = 0 ) {
		// phpcs:ignore
		$id = Cache::id( __FUNCTION__ . serialize( $filter ) . $extra_field . serialize( $extras ) . ( $not ? 'no' : 'yes') . $order . (string) $limit);
		$result = Cache::get_global( $id );
		if ( $result ) {
			return $result;
		}
		$where_extra = '';
		if ( 0 < count( $extras ) && '' !== $extra_field ) {
			$where_extra = ' AND ' . $extra_field . ( $not ? ' NOT' : '' ) . " IN ( '" . implode( "', '", $extras ) . "' )";
		}
		global $wpdb;
		$sql = 'SELECT * FROM ' . $wpdb->base_prefix . self::$statistics . ' WHERE (' . implode( ' AND ', $filter ) . ') ' . $where_extra . ' ' . $order . ( $limit > 0 ? 'LIMIT ' . $limit : '' ) . ';';
		// phpcs:ignore
		$result = $wpdb->get_results( $sql, ARRAY_A );
		if ( is_array( $result ) && 0 < count( $result ) ) {
			Cache::set_global( $id, $result, $cache ? 'infinite' : 'ephemeral' );
			return $result;
		}
		return [];
	}

	/**
	 * Get the grouped details.
	 *
	 * @param   string  $group       The group of the query.
	 * @param   array   $count       The sub-groups of the query.
	 * @param   array   $filter      The filter of the query.
	 * @param   boolean $cache       Has the query to be cached.
	 * @param   string  $extra_field Optional. The extra field to filter.
	 * @param   array   $extras      Optional. The extra values to match.
	 * @param   boolean $not         Optional. Exclude extra filter.
	 * @param   string  $order       Optional. The sort order of results.
	 * @param   integer $limit       Optional. The number of results to return.
	 * @return  array   The grouped details.
	 * @since    1.0.0
	 */
	public static function get_grouped_detail( $group, $count, $filter, $cache = true, $extra_field = '', $extras = [], $not = false, $order = '', $limit = 0 ) {
		// phpcs:ignore
		$id = Cache::id( __FUNCTION__ . $group . serialize( $count ) . serialize( $filter ) . $extra_field . serialize( $extras ) . ( $not ? 'no' : 'yes') . $order . (string) $limit);
		$result = Cache::get_global( $id );
		if ( $result ) {
			return $result;
		}
		$where_extra = '';
		if ( 0 < count( $extras ) && '' !== $extra_field ) {
			$where_extra = ' AND ' . $extra_field . ( $not ? ' NOT' : '' ) . " IN ( '" . implode( "', '", $extras ) . "' )";
		}
		$cnt = [];
		foreach ( $count as $c ) {
			$cnt[] = 'count(distinct(' . $c . ')) as cnt_' . $c;
		}
		$c = implode( ', ', $cnt );
		if ( 0 < strlen( $c ) ) {
			$c = $c . ', ';
		}
		global $wpdb;
		$sql  = 'SELECT *, ' . ( '' !== $group ? $group . ', ' : '' ) . $c . 'count(*) as records, avg(items) as avg_items, avg(size) as avg_size FROM ';
		$sql .= $wpdb->base_prefix . self::$details . ' WHERE (' . implode( ' AND ', $filter ) . ') ' . $where_extra . ' GROUP BY ' . $group . ' ' . $order . ( $limit > 0 ? 'LIMIT ' . $limit : '') .';';
		// phpcs:ignore
		$result = $wpdb->get_results( $sql, ARRAY_A );
		if ( is_array( $result ) && 0 < count( $result ) ) {
			Cache::set_global( $id, $result, $cache ? 'infinite' : 'ephemeral' );
			return $result;
		}
		return [];
	}
}
