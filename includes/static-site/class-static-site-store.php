<?php
/**
 * Static Site storage (spec 4.2, 4.4): the page table, the state rows (`current`
 * and `run`) and the invalidation journal, all InnoDB.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface EMCP_Tools_Static_Site_Storage {
	public function install(): bool;
	public function state( string $name ): ?array;
	public function put_state( string $name, ?string $old_raw, ?array $value ): bool;
	public function add_url( string $gen, string $url, string $status = 'pending' ): bool;
	public function rows( string $gen, array $statuses, int $limit, int $after_id = 0 ): array;
	public function counts( string $gen ): array;
	public function update_row( int $id, array $fields ): void;
	public function set_status( string $gen, string $from, string $to, bool $html_only = false ): void;
	public function find( string $gen, string $url ): ?array;
	public function manifest( string $gen ): array;
	public function taken_paths( string $gen ): array;
	public function drop_rows( string $gen ): void;
	public function journal_add( string $url ): void;
	public function journal_ack_all( string $run ): void;
	public function journal_pending( int $limit ): array;
	public function journal_ack( array $ids, string $run ): void;
	public function journal_prune( string $keep_run ): void;
	public function promote( array $current ): bool;
	public function current_locked(): ?array;
}

final class EMCP_Tools_Static_Site_Store implements EMCP_Tools_Static_Site_Storage {

	const UPDATABLE = array( 'status', 'file_path', 'http_code', 'content_type', 'content_hash', 'bytes', 'error' );

	/** @var EMCP_Tools_Static_Site_Db */
	private $db;

	public function __construct( ?EMCP_Tools_Static_Site_Db $db = null ) {
		$this->db = $db ?? new EMCP_Tools_Static_Site_Wpdb();
	}

	private function t( string $name ): string {
		return $this->db->prefix() . 'emcp_static_' . $name;
	}

	public function install(): bool {
		try {
			$this->db->query( "CREATE TABLE IF NOT EXISTS {$this->t( 'pages' )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				generation char(36) NOT NULL,
				url text NOT NULL,
				url_hash char(64) NOT NULL,
				file_path varchar(1024) DEFAULT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				http_code smallint(5) unsigned DEFAULT NULL,
				content_type varchar(100) DEFAULT NULL,
				content_hash char(32) DEFAULT NULL,
				bytes int(10) unsigned NOT NULL DEFAULT 0,
				error varchar(64) DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY gen_url (generation, url_hash),
				KEY gen_status (generation, status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
			$this->db->query( "CREATE TABLE IF NOT EXISTS {$this->t( 'state' )} (
				name varchar(32) NOT NULL,
				value longtext NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
			$this->db->query( "CREATE TABLE IF NOT EXISTS {$this->t( 'invalidations' )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url text NOT NULL,
				ack_run char(36) DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY ack_run (ack_run)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
			$this->db->query( "INSERT IGNORE INTO {$this->t( 'state' )} (name, value, updated_at) VALUES ('current', '{}', %s)", array( gmdate( 'Y-m-d H:i:s' ) ) );
			foreach ( array( 'pages', 'state', 'invalidations' ) as $table ) {
				$engine = $this->db->value( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( $this->t( $table ) ) );
				if ( 'innodb' !== strtolower( (string) $engine ) ) {
					return false;
				}
			}
			return true;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	public function state( string $name ): ?array {
		$raw = $this->db->value( "SELECT value FROM {$this->t( 'state' )} WHERE name = %s", array( $name ) );
		if ( null === $raw ) {
			return null;
		}
		$v = json_decode( (string) $raw, true );
		return is_array( $v ) ? $v + array( '_raw' => (string) $raw ) : null;
	}

	public function put_state( string $name, ?string $old_raw, ?array $value ): bool {
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( null !== $value ) {
			unset( $value['_raw'] );
			$json = (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( null === $old_raw ) {
			return null !== $value && 1 === $this->db->query( "INSERT IGNORE INTO {$this->t( 'state' )} (name, value, updated_at) VALUES (%s, %s, %s)", array( $name, $json, $now ) );
		}
		if ( null === $value ) {
			return 1 === $this->db->query( "DELETE FROM {$this->t( 'state' )} WHERE name = %s AND value = %s", array( $name, $old_raw ) );
		}
		if ( $json === $old_raw ) {
			return $this->db->value( "SELECT value FROM {$this->t( 'state' )} WHERE name = %s", array( $name ) ) === $old_raw;
		}
		return 1 === $this->db->query( "UPDATE {$this->t( 'state' )} SET value = %s, updated_at = %s WHERE name = %s AND value = %s", array( $json, $now, $name, $old_raw ) );
	}

	public function add_url( string $gen, string $url, string $status = 'pending' ): bool {
		return 1 === $this->db->query(
			"INSERT IGNORE INTO {$this->t( 'pages' )} (generation, url, url_hash, status) VALUES (%s, %s, %s, %s)",
			array( $gen, $url, EMCP_Tools_Static_Site_Paths::url_hash( $url ), $status )
		);
	}

	public function rows( string $gen, array $statuses, int $limit, int $after_id = 0 ): array {
		$in   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$rows = $this->db->rows(
			"SELECT * FROM {$this->t( 'pages' )} WHERE generation = %s AND status IN ({$in}) AND id > %d ORDER BY id LIMIT %d",
			array_merge( array( $gen ), array_values( $statuses ), array( $after_id, $limit ) )
		);
		foreach ( $rows as &$row ) {
			$row['id']    = (int) $row['id'];
			$row['bytes'] = (int) $row['bytes'];
		}
		return $rows;
	}

	public function counts( string $gen ): array {
		$out = array();
		foreach ( $this->db->rows( "SELECT status, COUNT(*) AS n FROM {$this->t( 'pages' )} WHERE generation = %s GROUP BY status", array( $gen ) ) as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	public function update_row( int $id, array $fields ): void {
		$sets = array();
		$args = array();
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, self::UPDATABLE, true ) ) {
				$sets[] = "{$k} = %s";
				$args[] = null === $v ? '' : (string) $v;
			}
		}
		if ( $sets ) {
			$args[] = $id;
			$this->db->query( "UPDATE {$this->t( 'pages' )} SET " . implode( ', ', $sets ) . ' WHERE id = %d', $args );
		}
	}

	public function set_status( string $gen, string $from, string $to, bool $html_only = false ): void {
		$this->db->query(
			"UPDATE {$this->t( 'pages' )} SET status = %s WHERE generation = %s AND status = %s" . ( $html_only ? " AND content_type LIKE 'text/html%'" : '' ),
			array( $to, $gen, $from )
		);
	}

	public function find( string $gen, string $url ): ?array {
		$rows = $this->db->rows( "SELECT * FROM {$this->t( 'pages' )} WHERE generation = %s AND url_hash = %s", array( $gen, EMCP_Tools_Static_Site_Paths::url_hash( $url ) ) );
		foreach ( $rows as $row ) {
			if ( $row['url'] === $url ) {
				$row['id'] = (int) $row['id'];
				return $row;
			}
		}
		return null;
	}

	public function manifest( string $gen ): array {
		$out = array();
		foreach ( $this->db->rows( "SELECT url_hash, file_path FROM {$this->t( 'pages' )} WHERE generation = %s AND status IN ('fetched','saved','zipped') AND file_path <> ''", array( $gen ) ) as $r ) {
			$out[ $r['url_hash'] ] = $r['file_path'];
		}
		return $out;
	}

	public function taken_paths( string $gen ): array {
		$out = array();
		foreach ( $this->db->rows( "SELECT url, file_path FROM {$this->t( 'pages' )} WHERE generation = %s AND file_path LIKE 'assets/%%'", array( $gen ) ) as $r ) {
			$out[ $r['file_path'] ] = $r['url'];
		}
		return $out;
	}

	public function drop_rows( string $gen ): void {
		$this->db->query( "DELETE FROM {$this->t( 'pages' )} WHERE generation = %s", array( $gen ) );
	}

	public function journal_add( string $url ): void {
		$this->db->query( "INSERT INTO {$this->t( 'invalidations' )} (url, created_at) VALUES (%s, %s)", array( $url, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public function journal_ack_all( string $run ): void {
		$this->db->query( "UPDATE {$this->t( 'invalidations' )} SET ack_run = %s WHERE ack_run IS NULL", array( $run ) );
	}

	public function journal_pending( int $limit ): array {
		$rows = $this->db->rows( "SELECT id, url FROM {$this->t( 'invalidations' )} WHERE ack_run IS NULL ORDER BY id LIMIT %d", array( $limit ) );
		foreach ( $rows as &$row ) {
			$row['id'] = (int) $row['id'];
		}
		return $rows;
	}

	public function journal_ack( array $ids, string $run ): void {
		$ids = array_values( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$this->db->query( "UPDATE {$this->t( 'invalidations' )} SET ack_run = %s WHERE id IN ({$in})", array_merge( array( $run ), $ids ) );
	}

	public function journal_prune( string $keep_run ): void {
		$this->db->query( "DELETE FROM {$this->t( 'invalidations' )} WHERE ack_run IS NOT NULL AND ack_run <> %s", array( $keep_run ) );
	}

	/**
	 * Move the pointer only when no invalidation is pending, atomically with the
	 * check (spec 4.4): lock the pointer row, locking-read the pending rows, update.
	 */
	public function promote( array $current ): bool {
		unset( $current['_raw'] );
		$state = $this->t( 'state' );
		// Non-waiting in spirit (spec 4.4): a purge's share lock held inside
		// another plugin's transaction must not hold this step for the server's
		// 50 s default, so wait 2 s and let the next step retry.
		// Best effort: a server that refuses the variable keeps its own wait.
		$wait = 0;
		try {
			$wait = (int) $this->db->value( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
			if ( $wait > 2 ) {
				$this->db->query( 'SET SESSION innodb_lock_wait_timeout = 2' );
			}
		} catch ( Throwable $e ) {
			$wait = 0;
		}
		try {
			return $this->promote_locked( $state, $current );
		} finally {
			if ( $wait > 2 ) {
				try {
					$this->db->query( 'SET SESSION innodb_lock_wait_timeout = ' . $wait );
				} catch ( Throwable $e ) {
					unset( $e );
				}
			}
		}
	}

	private function promote_locked( string $state, array $current ): bool {
		try {
			$this->db->query( 'START TRANSACTION' );
			$this->db->value( "SELECT value FROM {$state} WHERE name = 'current' FOR UPDATE" );
			$pending = (int) $this->db->value( "SELECT COUNT(*) FROM {$this->t( 'invalidations' )} WHERE ack_run IS NULL LOCK IN SHARE MODE" );
			if ( 0 !== $pending ) {
				$this->db->query( 'ROLLBACK' );
				return false;
			}
			$this->db->query( "UPDATE {$state} SET value = %s, updated_at = %s WHERE name = 'current'", array( (string) json_encode( $current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), gmdate( 'Y-m-d H:i:s' ) ) );
			$this->db->query( 'COMMIT' );
			return true;
		} catch ( Throwable $e ) {
			try {
				$this->db->query( 'ROLLBACK' );
			} catch ( Throwable $ignored ) {
				unset( $ignored );
			}
			return false;
		}
	}

	/** The pointer read by purges: a locking read waits for a promotion in progress. */
	public function current_locked(): ?array {
		$raw = $this->db->value( "SELECT value FROM {$this->t( 'state' )} WHERE name = 'current' LOCK IN SHARE MODE" );
		$v   = null === $raw ? null : json_decode( (string) $raw, true );
		return is_array( $v ) ? $v : null;
	}
}
