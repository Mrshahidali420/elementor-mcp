<?php
/**
 * Live smoke for the Static Site module on a real WordPress site, with the
 * Cloud calls stubbed. Restores everything it creates.
 *
 *   wp eval-file tests/support/verify-static-site.php --path=F:/laragon/www/msrplugins
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$fail = 0;
$ok   = static function ( string $name, bool $pass ) use ( &$fail ) {
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $pass ) {
		$fail++;
	}
};
$mu = WP_CONTENT_DIR . '/mu-plugins/emcp-static-smoke.php';
$saved_settings = get_option( EMCP_Tools_Static_Site_Module::OPTION_SETTINGS, null );
try {
	// Marker the crawler must see (proves rebuilds reach WordPress, spec 4.8).
	$marker = bin2hex( random_bytes( 6 ) );
	file_put_contents( $mu, "<?php add_action( 'wp_footer', static function () { echo '<!-- emcp-smoke-{$marker} -->'; } ); add_filter( 'https_local_ssl_verify', '__return_false' );" );

	EMCP_Tools_Static_Site_Module::maybe_install();
	$store   = new EMCP_Tools_Static_Site_Store();
	$credits = new class() implements EMCP_Tools_Static_Site_Credit_Api {
		public $calls = array();
		private function ok( string $op, array $args ): array {
			$this->calls[] = $op;
			return array( 'kind' => 'ok', 'status' => 200, 'code' => '', 'data' => array( 'id' => '00000000-0000-4000-8000-00000000c10d' ) );
		}
		public function reserve( string $r, string $h, string $m, string $s ): array { return $this->ok( 'reserve', func_get_args() ); }
		public function start( string $id, string $t ): array { return $this->ok( 'start', func_get_args() ); }
		public function complete( string $id, string $t, array $s ): array { return $this->ok( 'complete', func_get_args() ); }
		public function cancel( string $id, string $t ): array { return $this->ok( 'cancel', func_get_args() ); }
		public function void( string $r, string $t ): array { return $this->ok( 'void', func_get_args() ); }
	};
	$settings = EMCP_Tools_Static_Site_Module::sanitize( array( 'include' => array( 'pages', 'posts' ), 'accept_self_signed' => true ), 'local' );
	$runner   = new EMCP_Tools_Static_Site_Runner( array( 'store' => $store, 'credits' => $credits, 'base_dir' => EMCP_Tools_Static_Site_Module::base_dir(), 'site_url' => home_url( '/' ), 'site_uuid' => 'smoke', 'settings' => $settings ) );
	$owner    = 'smoke-' . bin2hex( random_bytes( 4 ) );

	$view = $runner->start( 'full', array(), $owner );
	if ( is_wp_error( $view ) ) {
		echo '  start refused: ' . $view->get_error_code() . ': ' . $view->get_error_message() . "
";
	}
	$ok( 'reservation accepted', is_array( $view ) && 'reserved' === $view['cloud'] );
	for ( $i = 0; $i < 2000 && is_array( $view ) && 'done' !== $view['phase']; $i++ ) {
		$view = $runner->step( $view['generation'], $owner, (int) $view['epoch'] );
	}
	$ok( 'generation finished', is_array( $view ) && 'done' === $view['phase'] );
	$ok( 'cloud calls in order', array( 'reserve', 'start', 'complete' ) === $credits->calls );
	$current = $store->state( 'current' );
	$dir     = EMCP_Tools_Static_Site_Module::base_dir() . '/' . $current['dir'];
	$zip     = new ZipArchive();
	$ok( 'zip opens', true === $zip->open( $dir . '/zips/' . $current['zip'] ) );
	$names = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$names[] = $zip->getNameIndex( $i );
	}
	$ok( 'zip has the home page', in_array( 'index.html', $names, true ) );
	$ok( 'zip has no executable names', ! preg_grep( '/\.(php\d?|phtml|phar|pl|cgi|sh)$/i', $names ) );
	$home = (string) $zip->getFromName( 'index.html' );
	$ok( 'crawler reached WordPress', str_contains( $home, 'emcp-smoke-' . $marker ) );
	$broken = 0;
	if ( preg_match_all( '#href="(?!https?:|//|\#|mailto:)([^"]+)"#', $home, $m ) ) {
		foreach ( array_slice( array_unique( $m[1] ), 0, 40 ) as $rel ) {
			$target = rtrim( preg_replace( '#\#.*$#', '', $rel ), '/' );
			$target = '' === $target || '.' === $target ? 'index.html' : ( str_ends_with( $target, '.html' ) || str_contains( basename( $target ), '.' ) ? $target : $target . '/index.html' );
			$target = ltrim( preg_replace( '#^\./#', '', $target ), '/' );
			if ( false === $zip->locateName( $target ) ) {
				$broken++;
				echo "  missing: {$rel}\n";
			}
		}
	}
	$ok( 'local links on the home page resolve inside the zip', 0 === $broken );
	$zip->close();

	// Serving: verify the deny check outcome is recorded, then decide() on the promoted tree.
	$server = new EMCP_Tools_Static_Site_Server( $store, new EMCP_Tools_Static_Site_Paths( home_url( '/' ) ), EMCP_Tools_Static_Site_Module::base_dir(), array( 'serve_enabled' => true, 'serve_logged_in' => false, 'serve_exclude_urls' => '' ), true );
	$file   = $server->decide( array( 'method' => 'GET', 'path' => '/', 'query' => '', 'cookies' => array(), 'crawl' => '', 'skip' => false ) );
	$ok( 'serving finds the promoted home page', null !== $file );
	$server->purge( array( home_url( '/' ) ) );
	$ok( 'purge removes it from serving', null === $server->decide( array( 'method' => 'GET', 'path' => '/', 'query' => '', 'cookies' => array(), 'crawl' => '', 'skip' => false ) ) );
	$ok( 'purge left a pending journal row', count( $store->journal_pending( 10 ) ) >= 1 );

	// Confinement of the local folder setting.
	$ok( 'local folder cannot leave its base', 'site' === EMCP_Tools_Static_Site_Module::sanitize( array( 'local_folder' => '../../wp-config' ) )['local_folder'] );

	$deny = EMCP_Tools_Static_Site_Deny_Check::run( EMCP_Tools_Static_Site_Module::base_dir(), EMCP_Tools_Static_Site_Module::base_url(), false );
	echo '  deny check on this server: ' . wp_json_encode( $deny ) . "\n";
	$ok( 'deny check answered', isset( $deny['passed'] ) );
} finally {
	@unlink( $mu );
	EMCP_Tools_Static_Site_Files::remove_tree( EMCP_Tools_Static_Site_Module::base_dir() );
	global $wpdb;
	foreach ( array( 'pages', 'invalidations' ) as $t ) {
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}emcp_static_{$t}" );
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}emcp_static_state WHERE name = 'run'" );
	$wpdb->query( "UPDATE {$wpdb->prefix}emcp_static_state SET value = '{}' WHERE name = 'current'" );
	null === $saved_settings ? delete_option( EMCP_Tools_Static_Site_Module::OPTION_SETTINGS ) : update_option( EMCP_Tools_Static_Site_Module::OPTION_SETTINGS, $saved_settings, false );
	delete_option( 'emcp_tools_lease_static-site-build' );
}
echo $fail ? "{$fail} check(s) failed\n" : "all checks passed\n";
exit( $fail ? 1 : 0 );
