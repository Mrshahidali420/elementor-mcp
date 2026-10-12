<?php
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-files.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-packager.php';

final class StaticSitePackagerTest extends \PHPUnit\Framework\TestCase {
	private $root;

	protected function setUp(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive missing.' );
		}
		$this->root = sys_get_temp_dir() . '/emcp-ss-zip-' . bin2hex( random_bytes( 4 ) );
		foreach ( array( 'index.html' => 'home', 'about/index.html' => 'about', 'assets/css/s.css' => 'css' ) as $p => $b ) {
			EMCP_Tools_Static_Site_Files::write( $this->root . '/build/' . $p, $b );
		}
	}

	protected function tearDown(): void {
		EMCP_Tools_Static_Site_Packager::$factory = null;
		EMCP_Tools_Static_Site_Files::remove_tree( (string) $this->root );
	}

	private function rows(): array {
		return array( array( 'id' => 1, 'file_path' => 'index.html' ), array( 'id' => 2, 'file_path' => 'about/index.html' ), array( 'id' => 3, 'file_path' => 'assets/css/s.css' ), array( 'id' => 4, 'file_path' => 'gone.html' ) );
	}

	public function test_marks_rows_only_after_a_successful_close(): void {
		$marked = array();
		$r      = EMCP_Tools_Static_Site_Packager::zip_batch( $this->root . '/site.zip', $this->root . '/build', $this->rows(), function ( $ok, $missing ) use ( &$marked ) {
			$marked = array( $ok, $missing );
		}, microtime( true ) + 20 );
		$this->assertSame( '', $r['error'] );
		$this->assertSame( array( array( 1, 2, 3 ), array( 4 ) ), $marked );
		$zip = new ZipArchive();
		$zip->open( $this->root . '/site.zip' );
		$this->assertSame( 'about', $zip->getFromName( 'about/index.html' ) );
		$zip->close();
	}

	public function test_failed_close_marks_nothing(): void {
		EMCP_Tools_Static_Site_Packager::$factory = static function () {
			return new class() extends ZipArchive {
				public function close(): bool {
					parent::close();
					return false;
				}
			};
		};
		$marked = null;
		$r      = EMCP_Tools_Static_Site_Packager::zip_batch( $this->root . '/site.zip', $this->root . '/build', $this->rows(), function ( $ok, $missing ) use ( &$marked ) {
			$marked = array( $ok, $missing );
		}, microtime( true ) + 20 );
		$this->assertSame( 'zip_close_failed', $r['error'] );
		$this->assertNull( $marked );
	}

	public function test_reopen_reconciles_without_duplicates(): void {
		$zip = new ZipArchive();
		$zip->open( $this->root . '/site.zip', ZipArchive::CREATE );
		$zip->addFromString( 'index.html', 'home' );
		$zip->close();
		$marked = array();
		EMCP_Tools_Static_Site_Packager::zip_batch( $this->root . '/site.zip', $this->root . '/build', array_slice( $this->rows(), 0, 2 ), function ( $ok ) use ( &$marked ) {
			$marked = $ok;
		}, microtime( true ) + 20 );
		$this->assertSame( array( 1, 2 ), $marked );
		$zip->open( $this->root . '/site.zip' );
		$this->assertSame( 2, $zip->numFiles );
		$zip->close();
	}

	public function test_a_file_the_archive_refuses_is_reported_not_retried(): void {
		EMCP_Tools_Static_Site_Packager::$factory = static fn() => new class() extends ZipArchive {
			#[\ReturnTypeWillChange]
			public function addFile( $filepath, $entryname = '', $start = 0, $length = 0, $flags = ZipArchive::FL_OVERWRITE ): bool {
				return 'about/index.html' === $entryname ? false : parent::addFile( $filepath, $entryname, $start, $length, $flags );
			}
		};
		$marked = array();
		$r      = EMCP_Tools_Static_Site_Packager::zip_batch( $this->root . '/site.zip', $this->root . '/build', $this->rows(), function ( $ok, $missing, $failed = array() ) use ( &$marked ) {
			$marked = array( $ok, $missing, $failed );
		}, microtime( true ) + 20 );
		$this->assertSame( '', $r['error'] );
		$this->assertSame( array( array( 1, 3 ), array( 4 ), array( 2 ) ), $marked );
	}
}
