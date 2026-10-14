<?php
/**
 * Row access for undoable database writes (3.20.0): primary keys, engines, locking reads
 * and keyed writes on $wpdb. EMCP_Tools_DB_Change runs every call through driver(), so
 * unit tests swap in in-memory tables with use_driver().
 *
 * @package EMCP_Tools
 * @since   3.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @since 3.20.0 */
class EMCP_Tools_DB_Rows {

	/** @var object|null */
	private static $driver = null;

	/**
	 * Use another driver (tests); null returns to $wpdb.
	 *
	 * @param object|null $driver Same methods as this class.
	 */
	public static function use_driver( ?object $driver ): void {
		self::$driver = $driver;
	}

	/** @return object The driver in use. */
	public static function driver(): object {
		if ( null === self::$driver ) {
			self::$driver = new self();
		}
		return self::$driver;
	}

	private static function quote( string $name ): string {
		return '`' . str_replace( '`', '', $name ) . '`';
	}

	/**
	 * The table's primary key columns in index order, or an empty list.
	 *
	 * @param string $table Validated table name.
	 * @return string[]
	 */
	public function primary_key( string $table ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- validated table name.
		$keys = (array) $wpdb->get_results( 'SHOW KEYS FROM ' . self::quote( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A );
		usort(
			$keys,
			static function ( $a, $b ) {
				return (int) ( $a['Seq_in_index'] ?? 0 ) <=> (int) ( $b['Seq_in_index'] ?? 0 );
			}
		);
		return array_values( array_filter( array_map( 'strval', array_column( $keys, 'Column_name' ) ) ) );
	}

	/**
	 * Whether the table's engine rolls back and locks rows (InnoDB).
	 *
	 * @param string $table Validated table name.
	 */
	public function transactional( string $table ): bool {
		global $wpdb;
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 'innodb' === strtolower( (string) $engine );
	}

	/**
	 * Rows matching an equality WHERE (null matches IS NULL, as $wpdb does).
	 *
	 * @param string $table Validated table name.
	 * @param array  $where Column => value.
	 * @param int    $limit Most rows to read.
	 * @param bool   $lock  Lock them for the running transaction (FOR UPDATE).
	 * @return array[]
	 */
	public function select( string $table, array $where, int $limit, bool $lock ): array {
		global $wpdb;
		$cond = array();
		$vals = array();
		foreach ( $where as $col => $val ) {
			if ( null === $val ) {
				$cond[] = self::quote( (string) $col ) . ' IS NULL';
				continue;
			}
			$cond[] = self::quote( (string) $col ) . ' = %s';
			$vals[] = (string) $val;
		}
		$sql = 'SELECT * FROM ' . self::quote( $table ) . ( $cond ? ' WHERE ' . implode( ' AND ', $cond ) : '' ) . ' LIMIT ' . max( 1, $limit ) . ( $lock ? ' FOR UPDATE' : '' );
		$rows = $wpdb->get_results( $vals ? $wpdb->prepare( $sql, $vals ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		// A failed read (a lock wait timeout, a deadlock) must never look like "no rows":
		// the caller's transaction catches this and changes nothing.
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( (string) $wpdb->last_error );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/** @return int|false */
	public function insert( string $table, array $row ) {
		global $wpdb;
		return $wpdb->insert( $table, $row );
	}

	public function insert_id(): int {
		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	/** @return int|false */
	public function update( string $table, array $data, array $where ) {
		global $wpdb;
		return $wpdb->update( $table, $data, $where );
	}

	/** @return int|false */
	public function delete( string $table, array $where ) {
		global $wpdb;
		return $wpdb->delete( $table, $where );
	}

	public function last_error(): string {
		global $wpdb;
		return (string) $wpdb->last_error;
	}
}
