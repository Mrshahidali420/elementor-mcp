<?php
/**
 * Deactivation stops EMCP's recurring maintenance events (they would keep firing
 * with no callback), keeps the user's backup schedule, and on a network-wide
 * deactivation does so on every site.
 */
use PHPUnit\Framework\TestCase;

final class DeactivatorTest extends TestCase {
	public function test_single_site_deactivation(): void {
		$this->run_fixture();
	}

	public function test_network_deactivation_covers_every_site(): void {
		$this->run_fixture( 'multisite' );
	}

	private function run_fixture( string $mode = '' ): void {
		$process = proc_open( array_filter( array( PHP_BINARY, __DIR__ . '/fixtures/deactivate.php', $mode ) ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		$this->assertSame( "PASS\n", $output, $error );
		$this->assertSame( 0, $code, $output . $error );
	}
}
