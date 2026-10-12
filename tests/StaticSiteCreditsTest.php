<?php
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-credits.php';
if ( ! defined( 'EMCP_TOOLS_VERSION' ) ) {
	define( 'EMCP_TOOLS_VERSION', '3.20.0' );
}

final class StaticSiteCreditsTest extends \PHPUnit\Framework\TestCase {

	public function test_classifies_outcomes(): void {
		$c = 'EMCP_Tools_Static_Site_Credits';
		$this->assertSame( 'ok', $c::classify( array( 'id' => 'g1' ) )['kind'] );
		$this->assertSame( 'refused', $c::classify( new WP_Error( 'not_connected', 'x' ) )['kind'] );
		foreach ( array( 401 => 'unauthorized', 402 => 'allowance_exhausted', 403 => 'site_not_registered', 409 => 'generation_active', 426 => 'plugin_outdated' ) as $status => $code ) {
			$r = $c::classify( new WP_Error( 'cloud_http_' . $status, $code ) );
			$this->assertSame( array( 'refused', $status, $code ), array( $r['kind'], $r['status'], $r['code'] ), (string) $status );
		}
		$this->assertSame( 'voided', $c::classify( new WP_Error( 'cloud_http_410', 'request_voided' ) )['kind'] );
		foreach ( array( new WP_Error( 'cloud_http_429', 'cloud_rate_limited' ), new WP_Error( 'cloud_http_503', 'static_sites_unavailable' ), new WP_Error( 'cloud_http_402', 'cloud_error' ), new WP_Error( 'http_request_failed', 'timeout' ) ) as $e ) {
			$this->assertSame( 'uncertain', $c::classify( $e )['kind'], $e->get_error_code() );
		}
	}

	public function test_sends_the_contract_bodies(): void {
		$calls   = array();
		$credits = new EMCP_Tools_Static_Site_Credits(
			function ( string $method, string $path, array $body ) use ( &$calls ) {
				$calls[] = array( $method, $path, $body );
				return array( 'id' => 'g1' );
			}
		);
		$credits->reserve( 'rid', str_repeat( 'a', 64 ), 'full', 'site-uuid' );
		$credits->start( 'g1', 'tok' );
		$credits->complete( 'g1', 'tok', array( 'pages' => 1, 'files' => 2, 'bytes' => 3, 'duration_seconds' => 4, 'errors' => 0, 'delivery' => 'zip' ) );
		$credits->cancel( 'g1', 'tok' );
		$credits->void( 'rid', 'tok' );
		$this->assertSame( array( 'POST', '/api/cloud/v1/static-sites/generations' ), array_slice( $calls[0], 0, 2 ) );
		$this->assertSame( array( 'site_uuid' => 'site-uuid', 'mode' => 'full', 'plugin_version' => '3.20.0', 'request_id' => 'rid', 'token_hash' => str_repeat( 'a', 64 ) ), $calls[0][2] );
		$this->assertSame( '/api/cloud/v1/static-sites/generations/g1/start', $calls[1][1] );
		$this->assertSame( array( 'token' => 'tok' ), $calls[1][2] );
		$this->assertSame( 'tok', $calls[2][2]['token'] );
		$this->assertSame( 'zip', $calls[2][2]['delivery'] );
		$this->assertSame( '/api/cloud/v1/static-sites/generations/g1/cancel', $calls[3][1] );
		$this->assertSame( '/api/cloud/v1/static-sites/generations/by-request/rid/void', $calls[4][1] );
	}
}
