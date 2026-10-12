<?php
/** mysqli adapter for real-MySQL Static Site tests (two connections = two "processes"). */
final class EMCP_Test_Static_Site_Mysqli_Db implements EMCP_Tools_Static_Site_Db {
	private $link;
	private $prefix;
	public function __construct( mysqli $link, string $prefix ) {
		$this->link   = $link;
		$this->prefix = $prefix;
	}
	public function prefix(): string {
		return $this->prefix;
	}
	private function bind( string $sql, array $args ): string {
		$i = 0;
		return (string) preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? null;
				return '%d' === $m[0] ? (string) (int) $v : "'" . $this->link->real_escape_string( (string) $v ) . "'";
			},
			$sql
		);
	}
	public function query( string $sql, array $args = array() ): int {
		$this->link->query( $this->bind( $sql, $args ) );
		return (int) $this->link->affected_rows;
	}
	public function value( string $sql, array $args = array() ) {
		$res = $this->link->query( $this->bind( $sql, $args ) );
		$row = $res instanceof mysqli_result ? $res->fetch_row() : null;
		return null === $row ? null : $row[0];
	}
	public function rows( string $sql, array $args = array() ): array {
		$res = $this->link->query( $this->bind( $sql, $args ) );
		return $res instanceof mysqli_result ? $res->fetch_all( MYSQLI_ASSOC ) : array();
	}
}
