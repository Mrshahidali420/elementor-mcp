<?php
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-paths.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-fetcher.php';

final class StaticSiteFetcherTest extends \PHPUnit\Framework\TestCase {

	/** @var array<int,array{0:string,1:array}> */
	private $calls = array();

	protected function tearDown(): void {
		EMCP_Tools_Static_Site_Fetcher::set_transport( null );
	}

	private function fetcher( array $answers, int $cap = 100 ): EMCP_Tools_Static_Site_Fetcher {
		$this->calls = array();
		EMCP_Tools_Static_Site_Fetcher::set_transport(
			function ( string $url, array $args ) use ( &$answers ) {
				$this->calls[] = array( $url, $args );
				return array_shift( $answers ) ?? new WP_Error( 'none', 'no answer' );
			}
		);
		return new EMCP_Tools_Static_Site_Fetcher( new EMCP_Tools_Static_Site_Paths( 'https://example.test' ), array( '/private/*' ), 'crawlkey', true, $cap );
	}

	private static function ok( string $body, string $type = 'text/html; charset=UTF-8' ): array {
		return array( 'code' => 200, 'headers' => array( 'content-type' => $type ), 'body' => $body );
	}

	private static function redirect( string $to ): array {
		return array( 'code' => 301, 'headers' => array( 'location' => $to ), 'body' => '' );
	}

	public function test_sends_crawl_key_and_disables_automatic_redirects(): void {
		$r = $this->fetcher( array( self::ok( 'hi' ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'text/html; charset=utf-8', $r['type'] );
		$args = $this->calls[0][1];
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 101, $args['limit_response_size'] );
		$this->assertSame( 'crawlkey', $args['headers']['X-EMCP-Static-Crawl'] );
		$this->assertLessThanOrEqual( 15, $args['timeout'] );
	}

	public function test_follows_same_origin_redirects(): void {
		$r = $this->fetcher( array( self::redirect( '/b/' ), self::redirect( 'https://example.test/c/' ), self::ok( 'done' ) ) )->fetch( 'https://example.test/a/', microtime( true ) + 20 );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'https://example.test/c/', $r['final'] );
		$this->assertCount( 3, $this->calls );
	}

	public function test_refuses_offsite_redirects_before_requesting_them(): void {
		foreach ( array( 'https://evil.test/', 'http://example.test/x', 'https://example.test:8443/x', '/private/area' ) as $target ) {
			$r = $this->fetcher( array( self::redirect( $target ), self::ok( 'never' ) ) )->fetch( 'https://example.test/a/', microtime( true ) + 20 );
			$this->assertSame( 'offsite_redirect', $r['error'], $target );
			$this->assertCount( 1, $this->calls, $target );
		}
	}

	public function test_refuses_a_fourth_hop(): void {
		$r = $this->fetcher( array( self::redirect( '/1/' ), self::redirect( '/2/' ), self::redirect( '/3/' ), self::redirect( '/4/' ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertSame( 'too_many_redirects', $r['error'] );
		$this->assertCount( 4, $this->calls );
	}

	public function test_rejects_oversized_bodies_instead_of_truncating(): void {
		$r = $this->fetcher( array( self::ok( str_repeat( 'x', 101 ) ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertSame( 'too_large', $r['error'] );
		$r = $this->fetcher( array( array( 'code' => 200, 'headers' => array( 'content-length' => '5000' ), 'body' => 'x' ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertSame( 'too_large', $r['error'] );
		$r = $this->fetcher( array( self::ok( str_repeat( 'x', 100 ) ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertTrue( $r['ok'] );
	}

	public function test_waits_when_the_budget_is_spent(): void {
		$r = $this->fetcher( array( self::ok( 'x' ) ) )->fetch( 'https://example.test/', microtime( true ) + 2 );
		$this->assertSame( 'no_time', $r['error'] );
		$this->assertCount( 0, $this->calls );
	}

	public function test_reports_http_errors_and_transport_failures(): void {
		$r = $this->fetcher( array( array( 'code' => 404, 'headers' => array(), 'body' => '' ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertSame( 'http_404', $r['error'] );
		$r = $this->fetcher( array( new WP_Error( 'http_request_failed', 'timeout' ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertSame( 'transport', $r['error'] );
	}

	public function test_a_transport_failure_keeps_its_reason_and_says_when_it_timed_out(): void {
		$r = $this->fetcher( array( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertSame( 'transport', $r['error'] );
		$this->assertSame( 'cURL error 7: Failed to connect', $r['detail'] );
		$this->assertFalse( $r['timed_out'] );
		$r = $this->fetcher( array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' ) ) )->fetch( 'https://example.test/', microtime( true ) + 20 );
		$this->assertTrue( $r['timed_out'] );
	}

	public function test_a_longer_request_timeout_can_be_asked_for(): void {
		$this->fetcher( array( self::ok( 'x' ) ) )->fetch( 'https://example.test/', microtime( true ) + 40, 30 );
		$this->assertSame( 30, $this->calls[0][1]['timeout'] );
	}
}
