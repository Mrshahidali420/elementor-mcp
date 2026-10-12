<?php
foreach ( array( 'paths', 'files', 'db', 'store', 'server' ) as $f ) {
	require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-' . $f . '.php';
}
require_once __DIR__ . '/support/static-site-memory-store.php';

final class StaticSiteServerTest extends \PHPUnit\Framework\TestCase {
	private $base;
	private $store;

	protected function setUp(): void {
		$this->base  = sys_get_temp_dir() . '/emcp-ss-srv-' . bin2hex( random_bytes( 4 ) );
		$this->store = new EMCP_Test_Static_Site_Memory_Store();
		$this->store->state['current'] = json_encode( array( 'dir' => 'gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ) );
		EMCP_Tools_Static_Site_Files::write( $this->base . '/gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve/about/index.html', 'about' );
		EMCP_Tools_Static_Site_Files::write( $this->base . '/gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve/café/index.html', 'cafe' );
		EMCP_Tools_Static_Site_Files::write( $this->base . '/gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve/index.html', 'home' );
	}

	protected function tearDown(): void {
		EMCP_Tools_Static_Site_Files::remove_tree( $this->base );
	}

	private function server( array $settings = array(), bool $verified = true ): EMCP_Tools_Static_Site_Server {
		return new EMCP_Tools_Static_Site_Server( $this->store, new EMCP_Tools_Static_Site_Paths( 'https://example.test' ), $this->base, $settings + array( 'serve_enabled' => true, 'serve_logged_in' => false, 'serve_exclude_urls' => "/cart/*" ), $verified );
	}

	private static function req( array $over = array() ): array {
		return $over + array( 'method' => 'GET', 'path' => '/about/', 'query' => '', 'cookies' => array(), 'crawl' => '', 'skip' => false );
	}

	public function test_serve_decision_matrix(): void {
		$s = $this->server();
		$this->assertStringEndsWith( 'gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve/about/index.html', $s->decide( self::req() ) );
		$this->assertStringEndsWith( 'gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve/index.html', $s->decide( self::req( array( 'path' => '/' ) ) ) );
		$this->assertNull( $s->decide( self::req( array( 'method' => 'POST' ) ) ) );
		$this->assertNull( $s->decide( self::req( array( 'query' => 'p=1' ) ) ) );
		$this->assertNull( $s->decide( self::req( array( 'skip' => true ) ) ) );
		$this->assertNull( $s->decide( self::req( array( 'cookies' => array( 'wordpress_logged_in_abc' ) ) ) ) );
		$this->assertNotNull( $this->server( array( 'serve_logged_in' => true ) )->decide( self::req( array( 'cookies' => array( 'wordpress_logged_in_abc' ) ) ) ) );
		$this->assertNull( $s->decide( self::req( array( 'path' => '/cart/x/' ) ) ) );
		$this->assertNull( $s->decide( self::req( array( 'path' => '/wp-sitemap.xml' ) ) ) );
		$this->assertNull( $s->decide( self::req( array( 'path' => '/missing/' ) ) ) );
		$this->assertNull( $this->server( array(), false )->decide( self::req() ) );
		$this->assertNull( $this->server( array( 'serve_enabled' => false ) )->decide( self::req() ) );
	}

	public function test_serves_percent_encoded_slug(): void {
		$this->assertStringEndsWith( 'gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve/café/index.html', $this->server()->decide( self::req( array( 'path' => '/caf%C3%A9/' ) ) ) );
	}

	public function test_crawler_requests_reach_wordpress(): void {
		$this->store->state['run'] = json_encode( array( 'crawl_key' => 'k1' ) );
		$s                         = $this->server();
		$this->assertNull( $s->decide( self::req( array( 'crawl' => 'k1' ) ) ) );
		$this->assertNotNull( $s->decide( self::req( array( 'crawl' => 'wrong' ) ) ) );
	}

	public function test_purge_journals_first_then_deletes_from_current(): void {
		$s = $this->server();
		$s->purge( array( 'https://example.test/about/' ) );
		$this->assertSame( array( 'https://example.test/about/' ), array_column( $this->store->journal_pending( 10 ), 'url' ) );
		$this->assertFileDoesNotExist( $this->base . '/gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve/about/index.html' );
		$this->assertFileExists( $this->base . '/gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve/index.html' );
		$s->purge( array( '*' ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/gen-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/serve' );
	}

	public function test_purge_journals_nothing_while_no_generation_exists(): void {
		unset( $this->store->state['current'] );
		$this->server()->purge( array( 'https://example.test/about/' ) );
		$this->assertSame( array(), $this->store->journal_pending( 10 ) );
	}
}
