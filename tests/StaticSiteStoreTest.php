<?php
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-paths.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-db.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-store.php';
require_once __DIR__ . '/support/static-site-mysqli-db.php';

/**
 * Real MySQL. Run with EMCP_SAFE_UPDATE_TEST_DB="host|user|password"; uses a
 * scratch database it creates and drops. Two mysqli connections stand in for
 * two PHP processes, which makes every interleaving deterministic.
 */
final class StaticSiteStoreTest extends \PHPUnit\Framework\TestCase {
	private $admin;
	private $name;
	private $a;
	private $b;
	private $sa;
	private $sb;

	protected function setUp(): void {
		$conf = getenv( 'EMCP_SAFE_UPDATE_TEST_DB' );
		if ( ! $conf || ! extension_loaded( 'mysqli' ) ) {
			$this->markTestSkipped( 'EMCP_SAFE_UPDATE_TEST_DB not set.' );
		}
		list( $host, $user, $pass ) = explode( '|', $conf ) + array( '', '', '' );
		mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
		$this->admin = new mysqli( $host, $user, $pass );
		$this->name  = 'emcp_ss_' . bin2hex( random_bytes( 4 ) );
		$this->admin->query( "CREATE DATABASE `{$this->name}`" );
		$this->a  = new mysqli( $host, $user, $pass, $this->name );
		$this->b  = new mysqli( $host, $user, $pass, $this->name );
		$this->sa = new EMCP_Tools_Static_Site_Store( new EMCP_Test_Static_Site_Mysqli_Db( $this->a, 'wp_' ) );
		$this->sb = new EMCP_Tools_Static_Site_Store( new EMCP_Test_Static_Site_Mysqli_Db( $this->b, 'wp_' ) );
		$this->assertTrue( $this->sa->install() );
		foreach ( array( $this->a, $this->b ) as $c ) {
			$c->query( 'SET SESSION innodb_lock_wait_timeout = 2' );
		}
	}

	protected function tearDown(): void {
		if ( $this->admin ) {
			$this->a->close();
			$this->b->close();
			$this->admin->query( "DROP DATABASE IF EXISTS `{$this->name}`" );
		}
	}

	public function test_full_urls_sharing_a_long_prefix_are_distinct_rows(): void {
		$prefix = 'https://example.test/wp-content/uploads/' . str_repeat( 'a', 170 );
		$this->assertTrue( $this->sa->add_url( 'g', $prefix . '/x.png?w=1' ) );
		$this->assertTrue( $this->sa->add_url( 'g', $prefix . '/x.png?w=2' ) );
		$this->assertFalse( $this->sa->add_url( 'g', $prefix . '/x.png?w=2' ) );
		$long = 'https://example.test/' . str_repeat( 'b', 1980 ) . '.css';
		$this->assertTrue( $this->sa->add_url( 'g', $long ) );
		$this->assertSame( $long, $this->sa->find( 'g', $long )['url'] );
		$this->assertSame( 3, array_sum( $this->sa->counts( 'g' ) ) );
	}

	public function test_state_compare_and_swap(): void {
		$this->assertTrue( $this->sa->put_state( 'run', null, array( 'epoch' => 1 ) ) );
		$this->assertFalse( $this->sb->put_state( 'run', null, array( 'epoch' => 9 ) ) );
		$run = $this->sa->state( 'run' );
		$this->assertTrue( $this->sa->put_state( 'run', $run['_raw'], array( 'epoch' => 2 ) ) );
		$this->assertFalse( $this->sb->put_state( 'run', $run['_raw'], array( 'epoch' => 3 ) ) );
		$this->assertSame( 2, $this->sb->state( 'run' )['epoch'] );
		$this->assertTrue( $this->sa->put_state( 'run', $this->sa->state( 'run' )['_raw'], null ) );
		$this->assertNull( $this->sa->state( 'run' ) );
	}

	public function test_promotion_waits_for_an_uncommitted_journal_row_and_counts_it(): void {
		$this->b->begin_transaction();
		$this->sb->journal_add( 'https://example.test/x/' );
		// a's locking read waits for b; with a 2 s lock wait it times out and promotion refuses.
		$this->assertFalse( $this->sa->promote( array( 'dir' => 'gen-new' ) ) );
		$this->b->commit();
		$this->assertFalse( $this->sa->promote( array( 'dir' => 'gen-new' ) ) );
		$this->sa->journal_ack( array_column( $this->sa->journal_pending( 10 ), 'id' ), str_repeat( 'r', 36 ) );
		$this->assertTrue( $this->sa->promote( array( 'dir' => 'gen-new' ) ) );
		$this->assertSame( 'gen-new', $this->sb->state( 'current' )['dir'] );
	}

	public function test_purge_after_promotion_reads_the_new_pointer(): void {
		$this->a->begin_transaction();
		$this->a->query( "SELECT value FROM wp_emcp_static_state WHERE name = 'current' FOR UPDATE" );
		$this->a->query( "UPDATE wp_emcp_static_state SET value = '{\"dir\":\"gen-new\"}' WHERE name = 'current'" );
		$this->sb->journal_add( 'https://example.test/y/' );
		// b's locking read cannot pass a's exclusive lock; it times out instead of reading the old value.
		$caught = false;
		try {
			$this->sb->current_locked();
		} catch ( Throwable $e ) {
			$caught = true;
		}
		$this->assertTrue( $caught );
		$this->a->commit();
		$this->assertSame( 'gen-new', $this->sb->current_locked()['dir'] );
	}

	public function test_out_of_order_commit_is_still_pending(): void {
		$this->b->begin_transaction();
		$this->sb->journal_add( 'https://example.test/low-id/' );
		$this->sa->journal_add( 'https://example.test/high-id/' );
		$pending = $this->sa->journal_pending( 10 );
		$this->assertSame( array( 'https://example.test/high-id/' ), array_column( $pending, 'url' ) );
		$this->sa->journal_ack( array_column( $pending, 'id' ), str_repeat( 'r', 36 ) );
		$this->b->commit();
		$this->assertSame( array( 'https://example.test/low-id/' ), array_column( $this->sa->journal_pending( 10 ), 'url' ) );
		$this->assertFalse( $this->sa->promote( array( 'dir' => 'gen-new' ) ) );
	}

	public function test_ack_all_marks_only_visible_rows(): void {
		$this->sa->journal_add( 'https://example.test/before/' );
		$this->sa->journal_ack_all( str_repeat( 'p', 36 ) );
		$this->assertSame( array(), $this->sa->journal_pending( 10 ) );
		$this->sa->journal_add( 'https://example.test/after/' );
		$this->assertCount( 1, $this->sa->journal_pending( 10 ) );
	}
}
