<?php
/** Lease store in memory. */
final class EMCP_Test_Lease_Memory implements EMCP_Tools_Lease_Store {
	public $rows = array();
	public function get( string $key ): ?string {
		return $this->rows[ $key ] ?? null;
	}
	public function insert( string $key, string $value ): bool {
		if ( isset( $this->rows[ $key ] ) ) {
			return false;
		}
		$this->rows[ $key ] = $value;
		return true;
	}
	public function swap( string $key, string $old, string $new ): bool {
		if ( ( $this->rows[ $key ] ?? null ) !== $old ) {
			return false;
		}
		$this->rows[ $key ] = $new;
		return true;
	}
	public function remove( string $key, string $old ): bool {
		if ( ( $this->rows[ $key ] ?? null ) !== $old ) {
			return false;
		}
		unset( $this->rows[ $key ] );
		return true;
	}
}

/** Scripted Cloud: each method pops its next answer, default ok. */
final class EMCP_Test_Static_Site_Credits implements EMCP_Tools_Static_Site_Credit_Api {
	public $answers = array();
	public $calls   = array();
	private function next( string $op, array $args ): array {
		$this->calls[] = array( $op, $args );
		if ( ! isset( $this->answers[ $op ] ) ) {
			$this->answers[ $op ] = array();
		}
		$a = array_shift( $this->answers[ $op ] ) ?? array( 'kind' => 'ok', 'status' => 200, 'code' => '', 'data' => array( 'id' => 'cloud-1', 'status' => 'reserved' ) );
		return is_callable( $a ) ? $a() : $a;
	}
	public function reserve( string $request_id, string $token_hash, string $mode, string $site_uuid ): array {
		return $this->next( 'reserve', func_get_args() );
	}
	public function start( string $id, string $token ): array {
		return $this->next( 'start', func_get_args() );
	}
	public function complete( string $id, string $token, array $stats ): array {
		return $this->next( 'complete', func_get_args() );
	}
	public function cancel( string $id, string $token ): array {
		return $this->next( 'cancel', func_get_args() );
	}
	public function void( string $request_id, string $token ): array {
		return $this->next( 'void', func_get_args() );
	}
	public static function answer( string $kind, int $status = 200, string $code = '', array $data = array() ): array {
		return array( 'kind' => $kind, 'status' => $status, 'code' => $code, 'data' => $data );
	}
}

/** Fetcher double: a map of URL => [body, content type] or an error string. */
final class EMCP_Test_Static_Site_Web {
	public $pages = array();
	public $calls = array();
	public function fetch( string $url, float $deadline ): array {
		$this->calls[] = $url;
		$p             = $this->pages[ $url ] ?? 'http_404';
		if ( is_string( $p ) ) {
			return array( 'ok' => false, 'code' => 404, 'type' => '', 'body' => '', 'final' => '', 'error' => $p );
		}
		return array( 'ok' => true, 'code' => 200, 'type' => $p[1] ?? 'text/html', 'body' => $p[0], 'final' => $url, 'error' => '' );
	}
}
