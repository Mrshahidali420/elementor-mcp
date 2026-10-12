<?php
require_once dirname( __DIR__ ) . '/includes/class-lease.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-locks.php';

final class StaticSiteLocksTest extends \PHPUnit\Framework\TestCase {
	private $taken = array();

	protected function setUp(): void {
		$this->taken = array();
		EMCP_Tools_Static_Site_Locks::use_impl(
			function ( string $op, string $name ) {
				if ( 'get' === $op ) {
					if ( isset( $this->taken[ $name ] ) ) {
						return false;
					}
					$this->taken[ $name ] = true;
					return true;
				}
				unset( $this->taken[ $name ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		EMCP_Tools_Static_Site_Locks::use_impl( null );
	}

	public function test_takes_in_order_and_releases_all(): void {
		$l = 'EMCP_Tools_Static_Site_Locks';
		$this->assertSame( 'ok', $l::with( array( $l::site(), $l::step( 'g' ) ), fn() => 'ok' ) );
		$this->assertSame( array(), $this->taken );
	}

	public function test_busy_lock_answers_409_and_takes_nothing(): void {
		$l                         = 'EMCP_Tools_Static_Site_Locks';
		$this->taken[ $l::step( 'g' ) ] = true;
		$r = $l::with( array( $l::site(), $l::step( 'g' ) ), fn() => 'never' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'busy', $r->get_error_code() );
		$this->assertArrayNotHasKey( $l::site(), $this->taken );
	}

	public function test_site_lock_is_refused_while_a_step_lock_is_held(): void {
		$l = 'EMCP_Tools_Static_Site_Locks';
		$this->expectException( LogicException::class );
		$l::with( array( $l::step( 'g' ) ), fn() => $l::take( array( $l::site() ) ) );
	}
}
