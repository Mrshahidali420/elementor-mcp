<?php
/**
 * Bounded HTTP fetch for the crawler (spec 2, 4.3, 4.4). Redirects are followed
 * by hand so every hop is checked before it is requested; WordPress truncates
 * silently at limit_response_size, so one extra byte is requested and any body
 * longer than the cap is refused.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Fetcher {

	const CAP         = 33554432;
	const MAX_HOPS    = 3;
	const MIN_TIMEOUT = 3;
	const MAX_TIMEOUT = 15;

	/** @var callable|null */
	private static $transport = null;

	/** @var EMCP_Tools_Static_Site_Paths */
	private $paths;
	/** @var string[] */
	private $exclude;
	/** @var string */
	private $crawl_key;
	/** @var bool */
	private $verify;
	/** @var int */
	private $cap;

	public static function set_transport( ?callable $t ): void {
		self::$transport = $t;
	}

	public function __construct( EMCP_Tools_Static_Site_Paths $paths, array $exclude, string $crawl_key, bool $verify_tls, int $cap = self::CAP ) {
		$this->paths     = $paths;
		$this->exclude   = $exclude;
		$this->crawl_key = $crawl_key;
		$this->verify    = $verify_tls;
		$this->cap       = $cap;
	}

	/**
	 * @param int $max Longest single request, in seconds (the one-off home
	 *                 page probe allows more than a crawl step does).
	 */
	public function fetch( string $url, float $deadline, int $max = self::MAX_TIMEOUT ): array {
		$current = $url;
		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			$left = $deadline - microtime( true );
			if ( $left < self::MIN_TIMEOUT ) {
				return self::fail( 'no_time' );
			}
			$res = $this->request( $current, (int) min( $max, floor( $left ) ) );
			if ( is_wp_error( $res ) ) {
				$detail = (string) $res->get_error_message();
				return self::fail( 'transport' ) + array(
					'detail'    => $detail,
					'timed_out' => (bool) preg_match( '/cURL error 28|timed out/i', $detail ),
				);
			}
			$code    = (int) $res['code'];
			$headers = array_change_key_case( (array) $res['headers'] );
			if ( $code >= 300 && $code < 400 ) {
				$loc  = (string) ( $headers['location'] ?? '' );
				$next = '' === $loc ? '' : $this->paths->normalize( $loc, $current );
				if ( '' === $next || ! $this->paths->is_same_origin( $next ) || $this->paths->is_excluded( $next, $this->exclude ) ) {
					return self::fail( 'offsite_redirect' );
				}
				if ( self::MAX_HOPS === $hop ) {
					break;
				}
				$current = $next;
				continue;
			}
			if ( '' !== (string) ( $headers['content-length'] ?? '' ) && (int) $headers['content-length'] > $this->cap ) {
				return self::fail( 'too_large' );
			}
			$body = (string) $res['body'];
			if ( strlen( $body ) > $this->cap ) {
				return self::fail( 'too_large' );
			}
			if ( $code < 200 || $code >= 300 ) {
				return self::fail( 'http_' . $code, $code );
			}
			return array(
				'ok'    => true,
				'code'  => $code,
				'type'  => strtolower( (string) ( $headers['content-type'] ?? '' ) ),
				'body'  => $body,
				'final' => $current,
				'error' => '',
			);
		}
		return self::fail( 'too_many_redirects' );
	}

	private static function fail( string $error, int $code = 0 ): array {
		return array( 'ok' => false, 'code' => $code, 'type' => '', 'body' => '', 'final' => '', 'error' => $error );
	}

	/** @return array{code:int,headers:array,body:string}|WP_Error */
	private function request( string $url, int $timeout ) {
		$args = array(
			'timeout'             => max( self::MIN_TIMEOUT, $timeout ),
			'redirection'         => 0,
			'sslverify'           => $this->verify,
			'limit_response_size' => $this->cap + 1,
			'user-agent'          => 'EMCP-Static/1',
			'headers'             => array(
				'X-EMCP-Static-Crawl' => $this->crawl_key,
				'Cache-Control'       => 'no-cache',
			),
		);
		if ( null !== self::$transport ) {
			return ( self::$transport )( $url, $args );
		}
		$res = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$raw     = wp_remote_retrieve_headers( $res );
		$all     = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		$headers = array();
		foreach ( $all as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $res ),
		);
	}
}
