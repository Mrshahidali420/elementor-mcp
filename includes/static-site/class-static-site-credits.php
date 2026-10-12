<?php
/**
 * EMCP Cloud credit calls for static-site generations (spec 4.5). Outcomes are
 * classified; the runner decides which ones are authoritative.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface EMCP_Tools_Static_Site_Credit_Api {
	public function reserve( string $request_id, string $token_hash, string $mode, string $site_uuid ): array;
	public function start( string $id, string $token ): array;
	public function complete( string $id, string $token, array $stats ): array;
	public function cancel( string $id, string $token ): array;
	public function void( string $request_id, string $token ): array;
}

final class EMCP_Tools_Static_Site_Credits implements EMCP_Tools_Static_Site_Credit_Api {

	const BASE            = '/api/cloud/v1/static-sites';
	const ALLOWANCE_CACHE = 'emcp_tools_static_allowance';
	/** Statuses whose JSON error the route itself returned before writing anything. */
	const REFUSALS = array( 401, 402, 403, 404, 409, 426 );

	/** @var callable|null */
	private $send;

	public function __construct( ?callable $send = null ) {
		$this->send = $send;
	}

	private function post( string $path, array $body ): array {
		$res = null !== $this->send
			? ( $this->send )( 'POST', self::BASE . $path, $body )
			: EMCP_Tools_Cloud_Client::request( 'POST', self::BASE . $path, $body );
		return self::classify( $res );
	}

	public function reserve( string $request_id, string $token_hash, string $mode, string $site_uuid ): array {
		return $this->post(
			'/generations',
			array(
				'site_uuid'      => $site_uuid,
				'mode'           => $mode,
				'plugin_version' => EMCP_TOOLS_VERSION,
				'request_id'     => $request_id,
				'token_hash'     => $token_hash,
			)
		);
	}

	public function start( string $id, string $token ): array {
		return $this->post( '/generations/' . rawurlencode( $id ) . '/start', array( 'token' => $token ) );
	}

	public function complete( string $id, string $token, array $stats ): array {
		return $this->post( '/generations/' . rawurlencode( $id ) . '/complete', array( 'token' => $token ) + $stats );
	}

	public function cancel( string $id, string $token ): array {
		return $this->post( '/generations/' . rawurlencode( $id ) . '/cancel', array( 'token' => $token ) );
	}

	public function void( string $request_id, string $token ): array {
		return $this->post( '/generations/by-request/' . rawurlencode( $request_id ) . '/void', array( 'token' => $token ) );
	}

	/** @return array{kind:string,status:int,code:string,data:array} */
	public static function classify( $res ): array {
		if ( ! is_wp_error( $res ) ) {
			return array( 'kind' => 'ok', 'status' => 200, 'code' => '', 'data' => (array) $res );
		}
		$code = (string) $res->get_error_code();
		if ( 'not_connected' === $code ) {
			return array( 'kind' => 'refused', 'status' => 0, 'code' => 'not_connected', 'data' => array() );
		}
		if ( preg_match( '/^cloud_http_(\d{3})$/', $code, $m ) ) {
			$status = (int) $m[1];
			$error  = (string) $res->get_error_message();
			if ( 410 === $status && 'request_voided' === $error ) {
				return array( 'kind' => 'voided', 'status' => 410, 'code' => $error, 'data' => array() );
			}
			if ( in_array( $status, self::REFUSALS, true ) && 'cloud_error' !== $error ) {
				return array( 'kind' => 'refused', 'status' => $status, 'code' => $error, 'data' => array() );
			}
			return array( 'kind' => 'uncertain', 'status' => $status, 'code' => $error, 'data' => array() );
		}
		return array( 'kind' => 'uncertain', 'status' => 0, 'code' => 'cloud_unreachable', 'data' => array() );
	}

	/** Cached 60 s; the screen asks for a fresh copy after it mounts. */
	public static function allowance( bool $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::ALLOWANCE_CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$res = EMCP_Tools_Cloud_Client::get( self::BASE . '/allowance' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		set_transient( self::ALLOWANCE_CACHE, $res, 60 );
		return $res;
	}

	public static function forget_allowance(): void {
		delete_transient( self::ALLOWANCE_CACHE );
	}
}
