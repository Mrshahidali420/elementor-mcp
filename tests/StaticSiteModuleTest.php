<?php
require_once dirname( __DIR__ ) . '/includes/modules/class-module.php';
require_once dirname( __DIR__ ) . '/includes/modules/static-site/class-static-site-module.php';
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return strip_tags( (string) $s );
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		$GLOBALS['emcp_test_cleared_crons'][] = $hook;
		return 0;
	}
}

final class StaticSiteModuleTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		emcp_test_reset();
	}

	public function test_defaults_and_identity(): void {
		$m = new EMCP_Tools_Static_Site_Module();
		$this->assertSame( 'static-site', $m->id() );
		$this->assertSame( 'free', $m->tier() );
		$this->assertFalse( $m->default_active() );
		$d = EMCP_Tools_Static_Site_Module::defaults();
		$this->assertSame( 'zip', $d['delivery'] );
		$this->assertSame( 'relative', $d['url_style'] );
		$this->assertSame( 50, $d['batch_size'] );
		$this->assertFalse( $d['serve_enabled'] );
		$this->assertFalse( $d['accept_self_signed'] );
	}

	public function test_sanitize_clamps_and_rejects_unsafe_values(): void {
		$s = EMCP_Tools_Static_Site_Module::sanitize(
			array(
				'delivery'           => 'ftp',
				'local_folder'       => '../../etc',
				'url_style'          => 'weird',
				'base_url'           => 'javascript:alert(1)',
				'include'            => array( 'pages', 'nope', 'posts' ),
				'batch_size'         => 9999,
				'accept_self_signed' => true,
				'serve_enabled'      => '1',
				'additional_urls'    => "https://example.test/x\n\n/y",
			)
		);
		$this->assertSame( 'zip', $s['delivery'] );
		$this->assertSame( 'site', $s['local_folder'] );
		$this->assertSame( 'relative', $s['url_style'] );
		$this->assertSame( '', $s['base_url'] );
		$this->assertSame( array( 'pages', 'posts' ), $s['include'] );
		$this->assertSame( 200, $s['batch_size'] );
		$this->assertTrue( $s['serve_enabled'] );
		$this->assertSame( "https://example.test/x\n/y", $s['additional_urls'] );
		$this->assertSame( 'my-site', EMCP_Tools_Static_Site_Module::sanitize( array( 'local_folder' => 'My Site' ) )['local_folder'] );
	}

	public function test_self_signed_is_only_kept_on_local_sites(): void {
		$this->assertFalse( EMCP_Tools_Static_Site_Module::sanitize( array( 'accept_self_signed' => true ), 'production' )['accept_self_signed'] );
		$this->assertTrue( EMCP_Tools_Static_Site_Module::sanitize( array( 'accept_self_signed' => true ), 'local' )['accept_self_signed'] );
	}

	public function test_plain_title_decodes_entities_for_display(): void {
		$this->assertSame( "Let\u{2019}s Keep In Touch", EMCP_Tools_Static_Site_Module::plain_title( 'Let&#8217;s Keep In Touch' ) );
		$this->assertSame( 'Fish & Chips "Best"', EMCP_Tools_Static_Site_Module::plain_title( 'Fish &amp; Chips &quot;Best&quot;' ) );
		$this->assertSame( 'a <b> c', EMCP_Tools_Static_Site_Module::plain_title( 'a &lt;b&gt; c' ) );
	}

	public function test_options_read_on_every_request_are_autoloaded(): void {
		EMCP_Tools_Static_Site_Module::save_settings( EMCP_Tools_Static_Site_Module::defaults() );
		EMCP_Tools_Static_Site_Module::save_deny( array( 'passed' => true, 'reason' => '', 'status' => 403 ) );
		$this->assertTrue( $GLOBALS['emcp_test']['autoload'][ EMCP_Tools_Static_Site_Module::OPTION_SETTINGS ] );
		$this->assertTrue( $GLOBALS['emcp_test']['autoload'][ EMCP_Tools_Static_Site_Module::OPTION_DENY ] );
	}

	public function test_switching_the_module_off_clears_its_cron(): void {
		$GLOBALS['emcp_test_cleared_crons'] = array();
		EMCP_Tools_Static_Site_Module::on_modules_changed( array( 'redirects' ), array( 'redirects', 'static-site' ) );
		$this->assertSame( array(), $GLOBALS['emcp_test_cleared_crons'] );
		EMCP_Tools_Static_Site_Module::on_modules_changed( array( 'redirects', 'static-site' ), array( 'redirects' ) );
		$this->assertSame( array( EMCP_Tools_Static_Site_Module::CRON ), $GLOBALS['emcp_test_cleared_crons'] );
		EMCP_Tools_Static_Site_Module::on_modules_changed( 'garbage', 'garbage' );
		$this->assertCount( 2, $GLOBALS['emcp_test_cleared_crons'] );
	}
}
