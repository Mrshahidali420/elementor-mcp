<?php
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-paths.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-registry.php';
require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-rewriter.php';

final class StaticSiteRewriterTest extends \PHPUnit\Framework\TestCase {

	/** Manifest of exported targets: normalised URL => output path. */
	private const MANIFEST = array(
		'https://example.test/'                          => 'index.html',
		'https://example.test/about/'                    => 'about/index.html',
		'https://example.test/about/team/'               => 'about/team/index.html',
		'https://example.test/wp-content/themes/t/s.css' => 'assets/css/s.css',
		'https://example.test/wp-content/themes/t/fonts/x.woff2' => 'assets/fonts/x.woff2',
		'https://example.test/img/a.jpg'                 => 'assets/images/a.jpg',
	);

	private function rewriter( string $style, string $base = '' ): EMCP_Tools_Static_Site_Rewriter {
		$paths = new EMCP_Tools_Static_Site_Paths( 'https://example.test' );
		return new EMCP_Tools_Static_Site_Rewriter(
			$paths,
			'https://example.test',
			static fn( string $url ) => self::MANIFEST[ $url ] ?? null,
			$style,
			$base
		);
	}

	public function test_relative_style_from_nested_page(): void {
		$html = '<a href="https://example.test/">Home</a><a href="/about/">A</a><img src="/img/a.jpg"><link href="/wp-content/themes/t/s.css">';
		$out  = $this->rewriter( 'relative' )->html( $html, 'https://example.test/about/team/', 'about/team/index.html' );
		$this->assertSame( '<a href="../../">Home</a><a href="../">A</a><img src="../../assets/images/a.jpg"><link href="../../assets/css/s.css">', $out );
	}

	public function test_offline_style_names_index_files(): void {
		$html = '<a href="/about/">A</a><a href="/">H</a>';
		$out  = $this->rewriter( 'offline' )->html( $html, 'https://example.test/about/team/', 'about/team/index.html' );
		$this->assertSame( '<a href="../index.html">A</a><a href="../../index.html">H</a>', $out );
		$root = $this->rewriter( 'offline' )->html( '<a href="/about/">A</a>', 'https://example.test/', 'index.html' );
		$this->assertSame( '<a href="about/index.html">A</a>', $root );
	}

	public function test_absolute_style_uses_base_url(): void {
		$out = $this->rewriter( 'absolute', 'https://static.example.com/' )->html( '<a href="/about/">A</a><a href="/">H</a><img src="/img/a.jpg">', 'https://example.test/', 'index.html' );
		$this->assertSame( '<a href="https://static.example.com/about/">A</a><a href="https://static.example.com/">H</a><img src="https://static.example.com/assets/images/a.jpg">', $out );
	}

	public function test_targets_outside_the_manifest_keep_their_live_url(): void {
		$out = $this->rewriter( 'relative' )->html( '<a href="/shop/?add=1">Shop</a><a href="contact/#form">C</a><a href="https://other.test/">O</a>', 'https://example.test/about/', 'about/index.html' );
		$this->assertSame( '<a href="https://example.test/shop/?add=1">Shop</a><a href="https://example.test/about/contact/#form">C</a><a href="https://other.test/">O</a>', $out );
	}

	public function test_fragments_survive_a_rewrite(): void {
		$out = $this->rewriter( 'relative' )->html( '<a href="/about/#team">A</a>', 'https://example.test/', 'index.html' );
		$this->assertSame( '<a href="about/#team">A</a>', $out );
	}

	public function test_srcset_and_inline_styles(): void {
		$html = '<img srcset="/img/a.jpg 1x, /img/missing.jpg 2x"><div style="background:url(\'/img/a.jpg\')"></div>';
		$out  = $this->rewriter( 'relative' )->html( $html, 'https://example.test/', 'index.html' );
		$this->assertSame( '<img srcset="assets/images/a.jpg 1x, https://example.test/img/missing.jpg 2x"><div style="background:url(\'assets/images/a.jpg\')"></div>', $out );
	}

	public function test_inline_script_asset_strings(): void {
		$out = $this->rewriter( 'relative' )->html( '<script>var f="https://example.test/img/a.jpg";</script>', 'https://example.test/', 'index.html' );
		$this->assertSame( '<script>var f="assets/images/a.jpg";</script>', $out );
	}

	public function test_css_relative_urls_follow_the_rehomed_file(): void {
		$css = '@font-face{src:url(fonts/x.woff2)} .a{background:url(/img/a.jpg)} @import "missing.css";';
		$out = $this->rewriter( 'relative' )->css( $css, 'https://example.test/wp-content/themes/t/s.css', 'assets/css/s.css' );
		$this->assertSame( '@font-face{src:url(../fonts/x.woff2)} .a{background:url(../images/a.jpg)} @import "https://example.test/wp-content/themes/t/missing.css";', $out );
	}

	public function test_entity_encoded_quotes_cannot_break_out_of_the_attribute(): void {
		$html = '<a href="/x?a=&quot;onmouseover=alert(1)&quot;">X</a><div style="background:url(&quot;/y?b=&lt;s&gt;&quot;)"></div>';
		$out  = $this->rewriter( 'relative' )->html( $html, 'https://example.test/', 'index.html' );
		$this->assertStringNotContainsString( '"onmouseover', $out );
		$this->assertStringNotContainsString( '<s>', $out );
		$this->assertSame( 1, preg_match_all( '/\bhref="[^"]*"/', $out ) );
		$this->assertStringContainsString( 'href="https://example.test/x?a=%22onmouseover=alert%281%29%22"', $out );
	}

	public function test_relative_helper(): void {
		$c = 'EMCP_Tools_Static_Site_Rewriter';
		$this->assertSame( 'index.html', $c::relative( '.', 'index.html' ) );
		$this->assertSame( '../index.html', $c::relative( 'about', 'index.html' ) );
		$this->assertSame( '../images/a.jpg', $c::relative( 'assets/css', 'assets/images/a.jpg' ) );
		$this->assertSame( 'team/index.html', $c::relative( 'about', 'about/team/index.html' ) );
	}
}
