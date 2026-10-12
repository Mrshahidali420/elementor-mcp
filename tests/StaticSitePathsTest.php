<?php
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-paths.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-registry.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-files.php';

final class StaticSitePathsTest extends \PHPUnit\Framework\TestCase {

	private function paths( string $site = 'https://example.test' ): EMCP_Tools_Static_Site_Paths {
		return new EMCP_Tools_Static_Site_Paths( $site );
	}

	public function test_normalize_resolves_forms_and_keeps_queries(): void {
		$p = $this->paths();
		$this->assertSame( 'https://example.test/about/', $p->normalize( '/about/#team' ) );
		$this->assertSame( 'https://example.test/a/b/c.css?ver=2', $p->normalize( '../c.css?ver=2', 'https://example.test/a/b/page/' ) );
		$this->assertSame( 'https://example.test/x.js', $p->normalize( '//example.test/x.js' ) );
		$this->assertSame( 'https://example.test/x.js', $p->normalize( 'https://EXAMPLE.test:443/x.js' ) );
		$this->assertSame( 'https://example.test/img.jpg?w=300&amp;h=1', $p->normalize( 'https://example.test/img.jpg?w=300&amp;amp;h=1' ) );
		foreach ( array( '', '#top', 'mailto:a@b.c', 'tel:1', 'javascript:void(0)', 'data:image/png;base64,AA', 'ftp://example.test/x' ) as $bad ) {
			$this->assertSame( '', $p->normalize( $bad ), $bad );
		}
	}

	public function test_non_default_port_is_part_of_the_origin(): void {
		$p = $this->paths( 'http://site.test:8080' );
		$this->assertSame( 'http://site.test:8080', $p->origin() );
		$this->assertSame( 'http://site.test:8080/x', $p->normalize( '/x' ) );
		$this->assertTrue( $p->is_same_origin( 'http://site.test:8080/x' ) );
		$this->assertFalse( $p->is_same_origin( 'http://site.test/x' ) );
		$this->assertFalse( $p->is_same_origin( 'https://site.test:8080/x' ) );
		$this->assertFalse( $p->is_same_origin( 'http://evil.test:8080/x' ) );
	}

	public function test_exclusions_support_wildcards_and_relative_patterns(): void {
		$p = $this->paths();
		$this->assertTrue( $p->is_excluded( 'https://example.test/cart/', array( '/cart' ) ) );
		$this->assertTrue( $p->is_excluded( 'https://example.test/shop/item/', array( '/shop/*' ) ) );
		$this->assertFalse( $p->is_excluded( 'https://example.test/about/', array( '/shop/*', '' ) ) );
	}

	public function test_page_paths_and_executable_extensions(): void {
		$p = $this->paths();
		$this->assertSame( 'index.html', $p->page_path( 'https://example.test/' ) );
		$this->assertSame( 'about/index.html', $p->page_path( 'https://example.test/about/' ) );
		$this->assertSame( 'about/team/index.html', $p->page_path( 'https://example.test/about/team' ) );
		$this->assertSame( 'legacy.html', $p->page_path( 'https://example.test/legacy.html' ) );
		$this->assertSame( 'wp-login.php/index.html', $p->page_path( 'https://example.test/wp-login.php' ) );
		$this->assertTrue( $p->is_page( 'https://example.test/wp-login.php' ) );
		$this->assertFalse( $p->is_page( 'https://example.test/a.css' ) );
		$this->assertSame( 'htaccess/index.html', $p->page_path( 'https://example.test/.htaccess' ) );
	}

	public function test_percent_encoded_slug_maps_identically(): void {
		$p = $this->paths();
		$this->assertSame( 'café/index.html', $p->page_path( 'https://example.test/caf%C3%A9/' ) );
		$this->assertSame( $p->page_path( 'https://example.test/caf%C3%A9/' ), $p->page_path( $p->normalize( '/caf%C3%A9/' ) ) );
	}

	public function test_asset_names_preserve_meaningful_queries(): void {
		$c = 'EMCP_Tools_Static_Site_Paths';
		$this->assertSame( 'style.css', $c::asset_name( 'https://example.test/wp-content/themes/t/style.css?ver=6.2' ) );
		$this->assertSame( 'style.css', $c::asset_name( 'https://example.test/style.css' ) );
		$a = $c::asset_name( 'https://example.test/img.jpg?w=300' );
		$b = $c::asset_name( 'https://example.test/img.jpg?w=600' );
		$this->assertMatchesRegularExpression( '/^img-[a-f0-9]{8}\.jpg$/', $a );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 'images', $this->paths()->asset_category( 'https://example.test/img.jpg?w=300' ) );
		$this->assertTrue( $this->paths()->has_query( 'https://example.test/?p=4' ) );
	}

	public function test_url_hash_covers_the_whole_url(): void {
		$c    = 'EMCP_Tools_Static_Site_Paths';
		$long = 'https://example.test/' . str_repeat( 'a', 200 );
		$this->assertNotSame( $c::url_hash( $long . '/x.css' ), $c::url_hash( $long . '/y.css' ) );
		$this->assertSame( 64, strlen( $c::url_hash( $long ) ) );
	}

	public function test_registry_disambiguates_collisions(): void {
		$r = new EMCP_Tools_Static_Site_Registry();
		$this->assertSame( 'assets/css/style.css', $r->path_for( 'https://e.test/wp-content/themes/t/style.css', 'css', 'style.css' ) );
		$this->assertSame( 'assets/css/foo-style.css', $r->path_for( 'https://e.test/wp-content/plugins/foo/style.css', 'css', 'style.css' ) );
		$this->assertSame( 'assets/css/foo-style-2.css', $r->path_for( 'https://e.test/wp-content/plugins/foo/x/style.css', 'css', 'style.css' ) );
		$this->assertSame( 'assets/css/style.css', $r->path_for( 'https://e.test/wp-content/themes/t/style.css', 'css', 'style.css' ) );
		$rebuilt = new EMCP_Tools_Static_Site_Registry( array( 'assets/css/style.css' => 'https://e.test/wp-content/themes/t/style.css' ) );
		$this->assertSame( 'assets/css/foo-style.css', $rebuilt->path_for( 'https://e.test/wp-content/plugins/foo/style.css', 'css', 'style.css' ) );
	}

	public function test_output_path_routes_pages_and_assets(): void {
		$p = $this->paths();
		$r = new EMCP_Tools_Static_Site_Registry();
		$this->assertSame( 'about/index.html', $p->output_path( 'https://example.test/about/', $r ) );
		$this->assertSame( 'assets/fonts/x.woff2', $p->output_path( 'https://example.test/f/x.woff2', $r ) );
	}

	public function test_confine_refuses_escapes(): void {
		$base = sys_get_temp_dir() . '/emcp-ss-' . bin2hex( random_bytes( 4 ) );
		mkdir( $base );
		try {
			$this->assertSame( str_replace( '\\', '/', $base ) . '/a/b.html', EMCP_Tools_Static_Site_Paths::confine( $base, 'a/b.html' ) );
			foreach ( array( '../x', 'a/../../x', '/etc/passwd', 'C:/x', "a\0b", 'a\\b', '', 'a//b', './a' ) as $bad ) {
				try {
					EMCP_Tools_Static_Site_Paths::confine( $base, $bad );
					$this->fail( 'accepted ' . $bad );
				} catch ( InvalidArgumentException $e ) {
					$this->assertSame( 'unsafe_path', $e->getMessage() );
				}
			}
			$outside = sys_get_temp_dir() . '/emcp-ss-out-' . bin2hex( random_bytes( 4 ) );
			mkdir( $outside );
			if ( @symlink( $outside, $base . '/link' ) ) {
				$this->expectException( InvalidArgumentException::class );
				EMCP_Tools_Static_Site_Paths::confine( $base, 'link/x.html' );
			}
		} finally {
			EMCP_Tools_Static_Site_Files::remove_tree( $base );
		}
	}

	public function test_files_write_and_protect(): void {
		$base = sys_get_temp_dir() . '/emcp-ss-' . bin2hex( random_bytes( 4 ) );
		try {
			EMCP_Tools_Static_Site_Files::protect_base( $base );
			$this->assertFileExists( $base . '/.htaccess' );
			$this->assertFileExists( $base . '/web.config' );
			$this->assertFileExists( $base . '/index.html' );
			$this->assertStringContainsString( 'Require all denied', file_get_contents( $base . '/.htaccess' ) );
			$this->assertSame( 5, EMCP_Tools_Static_Site_Files::write( $base . '/a/b/c.txt', 'hello' ) );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', EMCP_Tools_Static_Site_Files::random_hex( 16 ) );
		} finally {
			EMCP_Tools_Static_Site_Files::remove_tree( $base );
		}
		$this->assertDirectoryDoesNotExist( $base );
	}
}
