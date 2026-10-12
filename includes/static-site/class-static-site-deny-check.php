<?php
/**
 * Proves the web server refuses direct reads of generated files (spec 4.2).
 * Writes its own probe at the depth of served files, confirms it on disk,
 * requests it, and passes only on 401/403/404 without the probe's marker.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Deny_Check {

	/** @var callable|null */
	private static $transport = null;

	public static function set_transport( ?callable $t ): void {
		self::$transport = $t;
	}

	/** @return array{passed:bool,reason:string,status:int} */
	public static function run( string $base_dir, string $base_url, bool $verify_tls ): array {
		$probe  = 'gen-probe-' . EMCP_Tools_Static_Site_Files::random_hex( 8 );
		$name   = 'emcp-probe-' . EMCP_Tools_Static_Site_Files::random_hex( 8 ) . '.html';
		$marker = EMCP_Tools_Static_Site_Files::random_hex( 16 );
		$dir    = rtrim( $base_dir, '/' ) . '/' . $probe . '/serve';
		$file   = $dir . '/' . $name;
		try {
			try {
				EMCP_Tools_Static_Site_Files::write( $file, $marker );
			} catch ( Throwable $e ) {
				return self::result( false, 'probe_not_written' );
			}
			if ( ! is_file( $file ) || file_get_contents( $file ) !== $marker ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return self::result( false, 'probe_not_written' );
			}
			$res = self::request( rtrim( $base_url, '/' ) . '/' . $probe . '/serve/' . $name, $verify_tls );
			if ( is_wp_error( $res ) ) {
				return self::result( false, 'request_failed' );
			}
			$code = (int) $res['code'];
			if ( ! is_file( $file ) ) {
				return self::result( false, 'probe_vanished', $code );
			}
			if ( str_contains( (string) $res['body'], $marker ) ) {
				return self::result( false, 'readable', $code );
			}
			if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
				return self::result( true, '', $code );
			}
			return self::result( false, 'unexpected_status', $code );
		} finally {
			EMCP_Tools_Static_Site_Files::remove_tree( rtrim( $base_dir, '/' ) . '/' . $probe );
		}
	}

	private static function result( bool $passed, string $reason, int $status = 0 ): array {
		return array( 'passed' => $passed, 'reason' => $reason, 'status' => $status );
	}

	private static function request( string $url, bool $verify_tls ) {
		if ( null !== self::$transport ) {
			return ( self::$transport )( $url, array() );
		}
		$res = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => $verify_tls, 'limit_response_size' => 65536 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ) );
	}
}
