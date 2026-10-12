<?php
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-extractor.php';

final class StaticSiteExtractorTest extends \PHPUnit\Framework\TestCase {

	private const HTML = '<html><head>
		<link rel="stylesheet" href="/wp-content/themes/t/style.css?ver=2">
		<style>.h{background:url("/img/hero.jpg")}</style>
		<script src="https://example.test/wp-includes/js/jquery.js"></script>
		<script>var cfg = {"font":"https://example.test/fonts/x.woff2","api":"https://example.test/wp-json/"};</script>
	</head><body>
		<a href="/about/">About</a><a href="mailto:a@b.c">Mail</a>
		<img src="/img/a.jpg" srcset="/img/a-300.jpg 300w, /img/a-600.jpg 600w">
		<picture><source srcset="/img/b.webp 1x, /img/b@2x.webp 2x"></picture>
		<div style="background-image:url(/img/bg.png)"></div>
		<video poster="/img/poster.jpg"></video>
	</body></html>';

	public function test_finds_pages_and_assets(): void {
		$found = EMCP_Tools_Static_Site_Extractor::from_html( self::HTML, false, 'https://example.test' );
		foreach ( array( '/about/', '/wp-content/themes/t/style.css?ver=2', '/img/hero.jpg', 'https://example.test/wp-includes/js/jquery.js', 'https://example.test/fonts/x.woff2', '/img/a.jpg', '/img/a-300.jpg', '/img/a-600.jpg', '/img/b.webp', '/img/b@2x.webp', '/img/bg.png', '/img/poster.jpg' ) as $u ) {
			$this->assertContains( $u, $found, $u );
		}
		$this->assertNotContains( 'https://example.test/wp-json/', $found );
	}

	public function test_assets_only_skips_anchor_links(): void {
		$found = EMCP_Tools_Static_Site_Extractor::from_html( self::HTML, true, 'https://example.test' );
		$this->assertNotContains( '/about/', $found );
		$this->assertContains( '/img/a.jpg', $found );
	}

	public function test_css_urls_and_imports(): void {
		$css   = '@import "base.css"; @font-face{src:url(../fonts/x.woff2) format("woff2"),url(data:font/woff;base64,AA)} .a{background:url( \'img/a.png\' )}';
		$found = EMCP_Tools_Static_Site_Extractor::from_css( $css );
		$this->assertSame( array( '../fonts/x.woff2', 'img/a.png', 'base.css' ), $found );
	}

	public function test_empty_html_is_fine(): void {
		$this->assertSame( array(), EMCP_Tools_Static_Site_Extractor::from_html( '', false, 'https://example.test' ) );
	}
}
