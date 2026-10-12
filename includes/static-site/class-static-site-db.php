<?php
/**
 * Minimal DB seam for the Static Site store, so its locking SQL runs on real
 * MySQL in tests through two plain connections.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface EMCP_Tools_Static_Site_Db {
	public function prefix(): string;
	/** @return int Affected rows. Throws RuntimeException on error. */
	public function query( string $sql, array $args = array() ): int;
	/** @return mixed First column of the first row, or null. */
	public function value( string $sql, array $args = array() );
	/** @return array<int,array<string,mixed>> */
	public function rows( string $sql, array $args = array() ): array;
}

final class EMCP_Tools_Static_Site_Wpdb implements EMCP_Tools_Static_Site_Db {

	public function prefix(): string {
		global $wpdb;
		return $wpdb->prefix;
	}

	private function sql( string $sql, array $args ): string {
		global $wpdb;
		return array() === $args ? $sql : $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every caller passes placeholders.
	}

	private function check(): void {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'static_site_db_error' );
		}
	}

	public function query( string $sql, array $args = array() ): int {
		global $wpdb;
		$wpdb->query( $this->sql( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$this->check();
		return (int) $wpdb->rows_affected;
	}

	public function value( string $sql, array $args = array() ) {
		global $wpdb;
		$v = $wpdb->get_var( $this->sql( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$this->check();
		return $v;
	}

	public function rows( string $sql, array $args = array() ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $this->sql( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$this->check();
		return is_array( $rows ) ? $rows : array();
	}
}
