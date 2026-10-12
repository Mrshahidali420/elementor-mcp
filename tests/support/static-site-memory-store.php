<?php
/** In-memory EMCP_Tools_Static_Site_Storage for runner and server unit tests. */
final class EMCP_Test_Static_Site_Memory_Store implements EMCP_Tools_Static_Site_Storage {
	public $state   = array( 'current' => '{}' );
	public $pages   = array();
	public $journal = array();
	private $next   = 1;
	private $jnext  = 1;
	public function install(): bool {
		return true;
	}
	public function state( string $name ): ?array {
		if ( ! isset( $this->state[ $name ] ) ) {
			return null;
		}
		$v = json_decode( $this->state[ $name ], true );
		return is_array( $v ) ? $v + array( '_raw' => $this->state[ $name ] ) : null;
	}
	public function put_state( string $name, ?string $old_raw, ?array $value ): bool {
		$cur = $this->state[ $name ] ?? null;
		if ( $cur !== $old_raw ) {
			return false;
		}
		if ( null === $value ) {
			unset( $this->state[ $name ] );
		} else {
			unset( $value['_raw'] );
			$this->state[ $name ] = json_encode( $value, JSON_UNESCAPED_SLASHES );
		}
		return true;
	}
	public function add_url( string $gen, string $url, string $status = 'pending' ): bool {
		foreach ( $this->pages as $p ) {
			if ( $p['generation'] === $gen && $p['url'] === $url ) {
				return false;
			}
		}
		$id                 = $this->next++;
		$this->pages[ $id ] = array( 'id' => $id, 'generation' => $gen, 'url' => $url, 'url_hash' => hash( 'sha256', $url ), 'status' => $status, 'file_path' => null, 'content_type' => null, 'content_hash' => null, 'bytes' => 0, 'error' => null );
		return true;
	}
	public function rows( string $gen, array $statuses, int $limit, int $after_id = 0 ): array {
		$out = array();
		foreach ( $this->pages as $p ) {
			if ( $p['generation'] === $gen && in_array( $p['status'], $statuses, true ) && $p['id'] > $after_id ) {
				$out[] = $p;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}
	public function counts( string $gen ): array {
		$c = array();
		foreach ( $this->pages as $p ) {
			if ( $p['generation'] === $gen ) {
				$c[ $p['status'] ] = ( $c[ $p['status'] ] ?? 0 ) + 1;
			}
		}
		return $c;
	}
	public function update_row( int $id, array $fields ): void {
		$this->pages[ $id ] = array_merge( $this->pages[ $id ], $fields );
	}
	public function set_status( string $gen, string $from, string $to, bool $html_only = false ): void {
		foreach ( $this->pages as $id => $p ) {
			if ( $p['generation'] === $gen && $p['status'] === $from && ( ! $html_only || str_contains( (string) $p['content_type'], 'text/html' ) ) ) {
				$this->pages[ $id ]['status'] = $to;
			}
		}
	}
	public function find( string $gen, string $url ): ?array {
		foreach ( $this->pages as $p ) {
			if ( $p['generation'] === $gen && $p['url'] === $url ) {
				return $p;
			}
		}
		return null;
	}
	public function manifest( string $gen ): array {
		$m = array();
		foreach ( $this->pages as $p ) {
			if ( $p['generation'] === $gen && in_array( $p['status'], array( 'fetched', 'saved', 'zipped' ), true ) ) {
				$m[ $p['url_hash'] ] = $p['file_path'];
			}
		}
		return $m;
	}
	public function taken_paths( string $gen ): array {
		$m = array();
		foreach ( $this->pages as $p ) {
			if ( $p['generation'] === $gen && null !== $p['file_path'] && str_starts_with( (string) $p['file_path'], 'assets/' ) ) {
				$m[ $p['file_path'] ] = $p['url'];
			}
		}
		return $m;
	}
	public function drop_rows( string $gen ): void {
		$this->pages = array_filter( $this->pages, fn( $p ) => $p['generation'] !== $gen );
	}
	public function journal_add( string $url ): void {
		$id                   = $this->jnext++;
		$this->journal[ $id ] = array( 'id' => $id, 'url' => $url, 'ack_run' => null );
	}
	public function journal_ack_all( string $run ): void {
		foreach ( $this->journal as $id => $j ) {
			if ( null === $j['ack_run'] ) {
				$this->journal[ $id ]['ack_run'] = $run;
			}
		}
	}
	public function journal_pending( int $limit ): array {
		return array_slice( array_values( array_filter( $this->journal, fn( $j ) => null === $j['ack_run'] ) ), 0, $limit );
	}
	public function journal_ack( array $ids, string $run ): void {
		foreach ( $ids as $id ) {
			if ( isset( $this->journal[ $id ] ) ) {
				$this->journal[ $id ]['ack_run'] = $run;
			}
		}
	}
	public function journal_prune( string $keep_run ): void {
		$this->journal = array_filter( $this->journal, fn( $j ) => null === $j['ack_run'] || $j['ack_run'] === $keep_run );
	}
	/** @var callable|null Test hook run inside promote before the pending count. */
	public $before_count = null;
	public function promote( array $current ): bool {
		if ( $this->before_count ) {
			( $this->before_count )();
		}
		if ( $this->journal_pending( 1 ) ) {
			return false;
		}
		$this->state['current'] = json_encode( $current, JSON_UNESCAPED_SLASHES );
		return true;
	}
	public function current_locked(): ?array {
		return $this->state( 'current' );
	}
}
