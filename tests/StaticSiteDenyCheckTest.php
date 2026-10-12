<?php
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-files.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-deny-check.php';

final class StaticSiteDenyCheckTest extends \PHPUnit\Framework\TestCase {
	private $base;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/emcp-ss-deny-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base );
	}

	protected function tearDown(): void {
		EMCP_Tools_Static_Site_Deny_Check::set_transport( null );
		EMCP_Tools_Static_Site_Files::remove_tree( $this->base );
	}

	/** Transport that answers like a server reading the probe file from disk. */
	private function serve_files( int $code, bool $echo_file ) {
		EMCP_Tools_Static_Site_Deny_Check::set_transport(
			function ( string $url ) use ( $code, $echo_file ) {
				$rel  = substr( $url, strlen( 'https://example.test/wp-content/emcp-static/' ) );
				$body = $echo_file && is_file( $this->base . '/' . $rel ) ? file_get_contents( $this->base . '/' . $rel ) : 'Not here';
				return array( 'code' => $code, 'body' => $body );
			}
		);
	}

	private function run_check(): array {
		return EMCP_Tools_Static_Site_Deny_Check::run( $this->base, 'https://example.test/wp-content/emcp-static', true );
	}

	public function test_readable_directory_fails(): void {
		$this->serve_files( 200, true );
		$this->assertSame( array( false, 'readable' ), array_values( array_slice( $this->run_check(), 0, 2 ) ) );
	}

	public function test_forbidden_passes_and_cleans_up(): void {
		$this->serve_files( 403, false );
		$this->assertTrue( $this->run_check()['passed'] );
		$this->assertSame( array(), glob( $this->base . '/gen-probe-*' ) );
	}

	public function test_404_passes_only_without_the_marker(): void {
		$this->serve_files( 404, false );
		$this->assertTrue( $this->run_check()['passed'] );
		$this->serve_files( 404, true );
		$this->assertFalse( $this->run_check()['passed'] );
	}

	public function test_redirects_and_transport_errors_fail(): void {
		EMCP_Tools_Static_Site_Deny_Check::set_transport( fn() => array( 'code' => 302, 'body' => '' ) );
		$this->assertSame( 'unexpected_status', $this->run_check()['reason'] );
		EMCP_Tools_Static_Site_Deny_Check::set_transport( fn() => new WP_Error( 'x', 'down' ) );
		$this->assertSame( 'request_failed', $this->run_check()['reason'] );
	}

	public function test_unwritable_probe_fails_before_any_request(): void {
		$called = false;
		EMCP_Tools_Static_Site_Deny_Check::set_transport(
			function () use ( &$called ) {
				$called = true;
				return array( 'code' => 403, 'body' => '' );
			}
		);
		// A base that is a regular file: nothing can be created under it.
		file_put_contents( $this->base . '/not-a-dir', 'x' );
		$this->assertSame( 'probe_not_written', EMCP_Tools_Static_Site_Deny_Check::run( $this->base . '/not-a-dir', 'https://example.test/x', true )['reason'] );
		$this->assertFalse( $called );
	}
}
