<?php
foreach ( array( 'paths', 'registry', 'files', 'extractor', 'rewriter', 'fetcher', 'db', 'store', 'locks', 'credits', 'packager', 'runner' ) as $f ) {
	require_once dirname( __DIR__ ) . '/includes/static-site/class-static-site-' . $f . '.php';
}
require_once dirname( __DIR__ ) . '/includes/class-lease.php';
require_once __DIR__ . '/support/static-site-memory-store.php';
require_once __DIR__ . '/support/static-site-fakes.php';
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		$b    = random_bytes( 16 );
		$b[6] = chr( ord( $b[6] ) & 0x0f | 0x40 );
		$b[8] = chr( ord( $b[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	}
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() {
		return $GLOBALS['emcp_test_env_type'] ?? 'production';
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

final class StaticSiteRunnerTest extends \PHPUnit\Framework\TestCase {
	private $base;
	private $store;
	private $credits;
	private $web;
	private $lease_store;
	private $now;
	private $locks = array();

	protected function setUp(): void {
		$this->base        = sys_get_temp_dir() . '/emcp-ss-run-' . bin2hex( random_bytes( 4 ) );
		$this->store       = new EMCP_Test_Static_Site_Memory_Store();
		$this->credits     = new EMCP_Test_Static_Site_Credits();
		$this->web         = new EMCP_Test_Static_Site_Web();
		$this->lease_store = new EMCP_Test_Lease_Memory();
		$this->now         = 1000000;
		$this->locks       = array();
		EMCP_Tools_Static_Site_Locks::use_impl(
			function ( string $op, string $name ) {
				if ( 'get' === $op ) {
					if ( isset( $this->locks[ $name ] ) ) {
						return false;
					}
					return $this->locks[ $name ] = true;
				}
				unset( $this->locks[ $name ] );
				return true;
			}
		);
		$this->web->pages = array(
			'https://example.test/'                    => array( '<a href="/about/">About</a><link rel="stylesheet" href="/s.css"><a href="/?s=x">Search</a>' ),
			'https://example.test/about/'              => array( '<a href="/">Home</a><img src="/img/a.jpg">' ),
			'https://example.test/s.css'               => array( '.a{background:url(img/a.jpg)}', 'text/css' ),
			'https://example.test/img/a.jpg'           => array( 'JPEG', 'image/jpeg' ),
		);
	}

	protected function tearDown(): void {
		EMCP_Tools_Static_Site_Locks::use_impl( null );
		EMCP_Tools_Static_Site_Files::remove_tree( $this->base );
	}

	private function runner( array $over = array() ): EMCP_Tools_Static_Site_Runner {
		return new EMCP_Tools_Static_Site_Runner(
			$over + array(
				'store'     => $this->store,
				'credits'   => $this->credits,
				'lease'     => new EMCP_Tools_Lease( $this->lease_store, fn() => $this->now ),
				'base_dir'  => $this->base,
				'site_url'  => 'https://example.test',
				'site_uuid' => 'site-uuid',
				'settings'  => array( 'delivery' => 'zip', 'local_folder' => 'site', 'url_style' => 'relative', 'base_url' => '', 'include' => array( 'pages' ), 'additional_urls' => '', 'exclude_urls' => '', 'batch_size' => 50, 'accept_self_signed' => false ),
				'fetch'     => fn() => $this->web,
				'discover'  => static fn( array $cursor ) => array( 'urls' => array( 'https://example.test/' ), 'cursor' => null ),
				'budget'    => 20.0,
				'preflight' => static fn() => true,
				'reach'     => static fn() => true,
				'gone'      => static fn() => false,
				'log'       => static function () {},
			)
		);
	}

	/** Drive steps until done or a stop phase; returns the last answer. */
	private function drive( EMCP_Tools_Static_Site_Runner $r, array $view, string $owner, int $max = 50 ) {
		for ( $i = 0; $i < $max; $i++ ) {
			$view = $r->step( $view['generation'], $owner, (int) $view['epoch'] );
			if ( is_wp_error( $view ) || in_array( $view['phase'], array( 'done', 'cancelled' ), true ) ) {
				return $view;
			}
		}
		$this->fail( 'did not finish' );
	}

	public function test_full_generation_end_to_end(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'setup', $view['phase'] );
		$this->assertSame( 'reserved', $view['cloud'] );
		$this->assertSame( 'reserve', $this->credits->calls[0][0] );
		$this->assertSame( hash( 'sha256', $this->store->state( 'run' )['token'] ), $this->credits->calls[0][1][1] );
		$done = $this->drive( $r, $view, 'tab-a' );
		$this->assertSame( 'done', $done['phase'] );
		$ops = array_column( $this->credits->calls, 0 );
		$this->assertSame( array( 'reserve', 'start', 'complete' ), $ops );
		$this->assertSame( 'zip', $this->credits->calls[2][1][2]['delivery'] );
		$current = $this->store->state( 'current' );
		$dir     = $this->base . '/' . $current['dir'];
		$this->assertStringContainsString( 'href="../"', file_get_contents( $dir . '/build/about/index.html' ) );
		$this->assertStringContainsString( 'href="https://example.test/?s=x"', file_get_contents( $dir . '/build/index.html' ) );
		$this->assertStringContainsString( 'url(../images/a.jpg)', file_get_contents( $dir . '/build/assets/css/s.css' ) );
		$this->assertFileExists( $dir . '/serve/about/index.html' );
		$this->assertStringNotContainsString( 'href="../"', file_get_contents( $dir . '/serve/about/index.html' ) );
		$this->assertFileExists( $dir . '/zips/' . $current['zip'] );
		$this->assertNull( $this->store->state( 'run' ) );
		$this->assertSame( array(), $this->lease_store->rows );
		$this->assertNotContains( 'https://example.test/?s=x', $this->web->calls );
	}

	public function test_start_is_reported_before_the_first_fetch(): void {
		$this->credits->answers['start'] = array( EMCP_Test_Static_Site_Credits::answer( 'uncertain', 503 ) );
		$r                               = $this->runner();
		$view                            = $r->start( 'full', array(), 'tab-a' );
		$view                            = $r->step( $view['generation'], 'tab-a', 0 ); // setup
		$view                            = $r->step( $view['generation'], 'tab-a', 0 ); // discover
		$view                            = $r->step( $view['generation'], 'tab-a', 0 ); // start: uncertain
		$this->assertSame( 'start', $this->store->state( 'run' )['phase'] );
		$this->assertSame( array(), $this->web->calls );
		$r->step( $view['generation'], 'tab-a', 0 ); // start: ok
		$this->assertSame( 'running', $this->store->state( 'run' )['cloud'] );
		$this->assertSame( 'fetch', $this->store->state( 'run' )['phase'] );
		$this->assertCount( 2, array_filter( $this->credits->calls, fn( $c ) => 'start' === $c[0] ) );
	}

	public function test_first_attempt_refusal_clears_the_record(): void {
		$this->credits->answers['reserve'] = array( EMCP_Test_Static_Site_Credits::answer( 'refused', 402, 'allowance_exhausted' ) );
		$r                                 = $this->runner()->start( 'full', array(), 'tab-a' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'allowance_exhausted', $r->get_error_code() );
		$this->assertNull( $this->store->state( 'run' ) );
		$this->assertSame( array(), $this->lease_store->rows );
	}

	public function test_uncertain_first_reserve_is_resolved_by_retry(): void {
		$this->credits->answers['reserve'] = array(
			EMCP_Test_Static_Site_Credits::answer( 'uncertain', 503, 'static_sites_unavailable' ),
			EMCP_Test_Static_Site_Credits::answer( 'refused', 401, 'unauthorized' ),
			EMCP_Test_Static_Site_Credits::answer( 'refused', 402, 'allowance_exhausted' ),
			EMCP_Test_Static_Site_Credits::answer( 'ok', 200, '', array( 'id' => 'cloud-9' ) ),
		);
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'uncertain', $view['cloud'] );
		$token = $this->store->state( 'run' )['token'];
		$this->assertSame( 'uncertain', $r->resolve( $view['generation'] )['cloud'] );
		$this->assertSame( 'uncertain', $r->resolve( $view['generation'] )['cloud'] );
		$view = $r->resolve( $view['generation'] );
		$this->assertSame( 'reserved', $view['cloud'] );
		$this->assertSame( 'cloud-9', $this->store->state( 'run' )['cloud_id'] );
		$ids = array_unique( array_map( fn( $c ) => $c[1][0], array_filter( $this->credits->calls, fn( $c ) => 'reserve' === $c[0] ) ) );
		$this->assertCount( 1, $ids );
		$done = $this->drive( $r, $view, 'tab-a' );
		$this->assertSame( 'done', $done['phase'] );
		$complete = array_values( array_filter( $this->credits->calls, fn( $c ) => 'complete' === $c[0] ) )[0];
		$this->assertSame( array( 'cloud-9', $token ), array( $complete[1][0], $complete[1][1] ) );
	}

	public function test_voided_retry_clears_the_record(): void {
		$this->credits->answers['reserve'] = array( EMCP_Test_Static_Site_Credits::answer( 'uncertain' ), EMCP_Test_Static_Site_Credits::answer( 'voided', 410, 'request_voided' ) );
		$r                                 = $this->runner();
		$view                              = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( array( 'phase' => 'none' ), $r->resolve( $view['generation'] ) );
		$this->assertNull( $this->store->state( 'run' ) );
	}

	public function test_overlapping_generate_requests_create_one_record(): void {
		$r = $this->runner();
		$this->locks[ EMCP_Tools_Static_Site_Locks::site() ] = true;
		$busy = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'busy', $busy->get_error_code() );
		unset( $this->locks[ EMCP_Tools_Static_Site_Locks::site() ] );
		$first  = $r->start( 'full', array(), 'tab-a' );
		$second = $r->start( 'full', array(), 'tab-b' );
		$this->assertSame( 'run_exists', $second->get_error_code() );
		$this->assertCount( 1, array_filter( $this->credits->calls, fn( $c ) => 'reserve' === $c[0] ) );
		$this->assertSame( $first['generation'], $this->store->state( 'run' )['generation'] );
	}

	public function test_overlapping_steps_get_busy(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->locks[ EMCP_Tools_Static_Site_Locks::step( $view['generation'] ) ] = true;
		$this->assertSame( 'busy', $r->step( $view['generation'], 'tab-a', 0 )->get_error_code() );
	}

	public function test_takeover_after_lease_expiry_fences_the_old_tab(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$view = $r->step( $view['generation'], 'tab-a', 0 );
		$this->assertSame( 'elsewhere', $r->takeover( $view['generation'], 'tab-b' )->get_error_code() );
		$this->assertSame( 'elsewhere', $r->step( $view['generation'], 'tab-b', 0 )->get_error_code() );
		$this->now += 121;
		$this->locks[ EMCP_Tools_Static_Site_Locks::step( $view['generation'] ) ] = true;
		$this->assertSame( 'busy', $r->takeover( $view['generation'], 'tab-b' )->get_error_code() );
		unset( $this->locks[ EMCP_Tools_Static_Site_Locks::step( $view['generation'] ) ] );
		$taken = $r->takeover( $view['generation'], 'tab-b' );
		$this->assertSame( 1, $taken['epoch'] );
		$this->assertSame( 'stale', $r->step( $view['generation'], 'tab-a', 0 )->get_error_code() );
		$this->assertSame( 'done', $this->drive( $r, $taken, 'tab-b' )['phase'] );
	}

	public function test_stale_reserve_answer_after_handover_is_discarded_without_void(): void {
		$r = $this->runner();
		$this->credits->answers['reserve'] = array(
			function () {
				// The handover happens while the first reserve is in flight: the old
				// lease expires and tab-b takes it, then the record moves to epoch 1.
				$this->now += 121;
				( new EMCP_Tools_Lease( $this->lease_store, fn() => $this->now ) )->acquire( 'static-site-build', 'tab-b', 120 );
				$run          = $this->store->state( 'run' );
				$run['epoch'] = 1;
				$run['owner'] = 'tab-b';
				$this->store->put_state( 'run', $run['_raw'], $run );
				return EMCP_Test_Static_Site_Credits::answer( 'ok', 200, '', array( 'id' => 'cloud-1' ) );
			},
		);
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'reserving', $this->store->state( 'run' )['cloud'] );
		$this->assertNotContains( 'void', array_column( $this->credits->calls, 0 ) );
		$view = $r->resolve( $view['generation'] );
		$this->assertSame( 'reserved', $view['cloud'] );
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-b' )['phase'] );
	}

	public function test_late_reserve_success_for_a_cancelling_run_keeps_the_id_and_cancels(): void {
		$r = $this->runner();
		$this->credits->answers['reserve'] = array(
			function () {
				$run          = $this->store->state( 'run' );
				$run['cloud'] = 'cancelling';
				$this->store->put_state( 'run', $run['_raw'], $run );
				return EMCP_Test_Static_Site_Credits::answer( 'ok', 200, '', array( 'id' => 'cloud-7' ) );
			},
		);
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'cloud-7', $this->store->state( 'run' )['cloud_id'] );
		$this->assertNotSame( 'setup', $this->store->state( 'run' )['phase'] );
		$r->resolve( $view['generation'] );
		$cancel = array_values( array_filter( $this->credits->calls, fn( $c ) => 'cancel' === $c[0] ) );
		$this->assertSame( 'cloud-7', $cancel[0][1][0] );
		$this->assertNull( $this->store->state( 'run' ) );
	}

	public function test_cancel_before_any_cloud_id_voids_the_request(): void {
		$this->credits->answers['reserve'] = array( EMCP_Test_Static_Site_Credits::answer( 'uncertain' ) );
		$r                                 = $this->runner();
		$view                              = $r->start( 'full', array(), 'tab-a' );
		$out                               = $r->cancel( $view['generation'], 'tab-a', 0 );
		$this->assertSame( 'cancelled', $out['phase'] );
		$this->assertSame( 'void', end( $this->credits->calls )[0] );
		$this->assertNull( $this->store->state( 'run' ) );
	}

	public function test_uncertain_cancel_keeps_the_record_and_blocks_a_new_generation(): void {
		$this->credits->answers['cancel'] = array( EMCP_Test_Static_Site_Credits::answer( 'uncertain', 503 ) );
		$r                                = $this->runner();
		$view                             = $r->start( 'full', array(), 'tab-a' );
		$r->cancel( $view['generation'], 'tab-a', 0 );
		$this->assertSame( 'cancelling', $this->store->state( 'run' )['cloud'] );
		$this->assertSame( 'run_exists', $r->start( 'full', array(), 'tab-a' )->get_error_code() );
		$r->resolve( $view['generation'] );
		$this->assertNull( $this->store->state( 'run' ) );
	}

	public function test_cancel_after_promotion_is_refused_and_deletes_nothing(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->credits->answers['complete'] = array( EMCP_Test_Static_Site_Credits::answer( 'uncertain', 503 ), EMCP_Test_Static_Site_Credits::answer( 'uncertain', 503 ) );
		for ( $i = 0; $i < 40 && 'complete' !== ( $view['phase'] ?? '' ); $i++ ) {
			$view = $r->step( $view['generation'], 'tab-a', 0 );
		}
		$this->assertSame( 'complete', $view['phase'] );
		$dir = $this->base . '/' . $this->store->state( 'current' )['dir'];
		$this->assertSame( 'already_promoted', $r->cancel( $view['generation'], 'tab-a', 0 )->get_error_code() );
		$this->assertDirectoryExists( $dir );
		$this->assertTrue( $r->view()['promoted'] );
	}

	public function test_invalidation_during_a_run_is_rechecked_before_promotion(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		for ( $i = 0; $i < 40 && 'recheck' !== $this->store->state( 'run' )['phase']; $i++ ) {
			$view = $r->step( $view['generation'], 'tab-a', 0 );
			if ( 'rewrite' === $this->store->state( 'run' )['phase'] && ! $this->store->journal ) {
				$this->store->journal_add( 'https://example.test/about/' );
				$this->web->pages['https://example.test/about/'] = array( '<p>new body</p>' );
			}
		}
		$done = $this->drive( $r, $view, 'tab-a' );
		$this->assertSame( 'done', $done['phase'] );
		$dir = $this->base . '/' . $this->store->state( 'current' )['dir'];
		$this->assertStringContainsString( 'new body', file_get_contents( $dir . '/serve/about/index.html' ) );
		$this->assertSame( array(), $this->store->journal_pending( 10 ) );
	}

	public function test_password_protected_page_is_dropped_from_serving(): void {
		$protected = false;
		$r         = $this->runner( array( 'gone' => function ( string $url ) use ( &$protected ) {
			return $protected && 'https://example.test/about/' === $url;
		} ) );
		$view = $r->start( 'full', array(), 'tab-a' );
		for ( $i = 0; $i < 40 && 'rewrite' !== $this->store->state( 'run' )['phase']; $i++ ) {
			$view = $r->step( $view['generation'], 'tab-a', 0 );
		}
		$protected = true;
		$this->store->journal_add( 'https://example.test/about/' );
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-a' )['phase'] );
		$dir = $this->base . '/' . $this->store->state( 'current' )['dir'];
		$this->assertFileDoesNotExist( $dir . '/serve/about/index.html' );
	}

	public function test_promotion_waits_when_an_invalidation_arrives_after_the_recheck(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$once = false;
		$this->store->before_count = function () use ( &$once ) {
			if ( ! $once ) {
				$once = true;
				$this->store->journal_add( 'https://example.test/' );
			}
		};
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-a' )['phase'] );
		$this->assertTrue( $once );
		$this->assertSame( array(), $this->store->journal_pending( 10 ) );
	}

	public function test_pages_mode_fetches_assets_but_not_linked_pages(): void {
		$r    = $this->runner();
		$view = $r->start( 'pages', array( 'https://example.test/about/' ), 'tab-a' );
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-a' )['phase'] );
		$this->assertContains( 'https://example.test/img/a.jpg', $this->web->calls );
		$this->assertNotContains( 'https://example.test/', $this->web->calls );
		$dir = $this->base . '/' . $this->store->state( 'current' )['dir'];
		$this->assertStringContainsString( 'href="https://example.test/"', file_get_contents( $dir . '/build/about/index.html' ) );
	}

	public function test_switched_off_asset_folders_are_not_copied_and_keep_their_live_address(): void {
		$this->web->pages['https://example.test/'] = array( '<link rel="stylesheet" href="/wp-content/themes/t/s.css"><img src="/wp-content/uploads/a.jpg"><script src="/wp-content/plugins/p/x.js"></script>' );
		$this->web->pages['https://example.test/wp-content/themes/t/s.css'] = array( '.a{color:red}', 'text/css' );
		$this->web->pages['https://example.test/wp-content/uploads/a.jpg']  = array( 'JPEG', 'image/jpeg' );
		$this->web->pages['https://example.test/wp-content/plugins/p/x.js']  = array( 'x()', 'application/javascript' );
		$settings = array( 'delivery' => 'zip', 'local_folder' => 'site', 'url_style' => 'relative', 'base_url' => '', 'include' => array( 'pages', 'theme_assets' ), 'additional_urls' => '', 'exclude_urls' => '', 'batch_size' => 50, 'accept_self_signed' => false );
		$roots    = array( 'theme_assets' => '/wp-content/themes/', 'plugin_assets' => '/wp-content/plugins/', 'uploads' => '/wp-content/uploads/' );
		$r        = $this->runner( array( 'settings' => $settings, 'asset_roots' => $roots ) );
		$view     = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-a' )['phase'] );
		$this->assertContains( 'https://example.test/wp-content/themes/t/s.css', $this->web->calls );
		$this->assertNotContains( 'https://example.test/wp-content/uploads/a.jpg', $this->web->calls );
		$this->assertNotContains( 'https://example.test/wp-content/plugins/p/x.js', $this->web->calls );
		$html = file_get_contents( $this->base . '/' . $this->store->state( 'current' )['dir'] . '/build/index.html' );
		$this->assertStringContainsString( 'src="https://example.test/wp-content/uploads/a.jpg"', $html );
		$this->assertStringContainsString( 'src="https://example.test/wp-content/plugins/p/x.js"', $html );
		$this->assertStringNotContainsString( 'href="https://example.test/wp-content/themes/t/s.css"', $html );
	}

	public function test_non_html_responses_are_never_written_as_pages(): void {
		$this->web->pages['https://example.test/']           = array( '<a href="/feed/">Feed</a><link rel="stylesheet" href="/odd.css">' );
		$this->web->pages['https://example.test/feed/']      = array( '<rss><item><title>&lt;script&gt;alert(1)&lt;/script&gt;</title></item></rss>', 'application/rss+xml; charset=UTF-8' );
		$this->web->pages['https://example.test/odd.css']    = array( '<p>an html body behind a css url</p>', 'text/html' );
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-a' )['phase'] );
		$dir = $this->base . '/' . $this->store->state( 'current' )['dir'];
		$this->assertFileDoesNotExist( $dir . '/serve/feed/index.html' );
		$this->assertFileDoesNotExist( $dir . '/build/feed/index.html' );
		$this->assertFileDoesNotExist( $dir . '/serve/odd.css/index.html' );
		$this->assertFileExists( $dir . '/build/assets/css/odd.css' );
		$this->assertSame( 'not_html', $this->store->find( $view['generation'], 'https://example.test/feed/' )['error'] );
	}

	public function test_recheck_drops_a_page_that_stopped_answering_html(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		for ( $i = 0; $i < 40 && 'rewrite' !== $this->store->state( 'run' )['phase']; $i++ ) {
			$view = $r->step( $view['generation'], 'tab-a', 0 );
		}
		$this->web->pages['https://example.test/about/'] = array( '{"html":"<script>alert(1)</script>"}', 'application/json' );
		$this->store->journal_add( 'https://example.test/about/' );
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-a' )['phase'] );
		$dir = $this->base . '/' . $this->store->state( 'current' )['dir'];
		$this->assertFileDoesNotExist( $dir . '/serve/about/index.html' );
	}

	public function test_budget_handles_unlimited_execution_time(): void {
		$old = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '0' );
		$this->assertSame( 20.0, EMCP_Tools_Static_Site_Runner::budget() );
		ini_set( 'max_execution_time', '12' );
		$this->assertSame( 7.0, EMCP_Tools_Static_Site_Runner::budget() );
		ini_set( 'max_execution_time', (string) $old );
	}

	public function test_lock_order_is_never_violated(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-a' )['phase'] );
		$this->assertSame( array(), EMCP_Tools_Static_Site_Locks::held() );
	}

	public function test_unreachable_site_is_refused_before_anything_is_reserved(): void {
		$this->web->pages['https://example.test/'] = 'transport';
		$r = $this->runner( array( 'reach' => null ) )->start( 'full', array(), 'tab-a' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'site_unreachable', $r->get_error_code() );
		$this->assertSame( array(), $this->credits->calls );
		$this->assertNull( $this->store->state( 'run' ) );
		$this->assertSame( array(), $this->lease_store->rows );
	}

	public function test_a_slow_home_page_is_reported_as_slow_not_unreachable(): void {
		$this->web->pages['https://example.test/'] = 'timeout';
		$r = $this->runner( array( 'reach' => null ) )->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'site_slow', $r->get_error_code() );
		$this->assertStringContainsString( 'too long', $r->get_error_message() );
		$this->assertSame( array(), $this->credits->calls );
	}

	public function test_unreachable_site_names_the_certificate_setting(): void {
		$GLOBALS['emcp_test_env_type']             = 'local';
		$this->web->pages['https://example.test/'] = 'transport';
		try {
			$r = $this->runner( array( 'reach' => null ) )->start( 'full', array(), 'tab-a' );
		} finally {
			unset( $GLOBALS['emcp_test_env_type'] );
		}
		$this->assertSame( 'site_unreachable', $r->get_error_code() );
		$this->assertStringContainsString( 'self-signed', $r->get_error_message() );
	}

	public function test_reachable_site_reserves_as_before(): void {
		$this->web->pages['https://example.test/'] = array( '<html></html>' );
		$view = $this->runner( array( 'reach' => null ) )->start( 'full', array(), 'tab-a' );
		$this->assertIsArray( $view );
		$this->assertSame( 'reserved', $view['cloud'] );
		$this->assertSame( array( 'https://example.test/' ), $this->web->calls );
	}

	public function test_redirecting_home_page_is_refused_before_anything_is_reserved(): void {
		$this->web->pages['https://example.test/'] = 'offsite_redirect';
		$r = $this->runner( array( 'reach' => null ) )->start( 'full', array(), 'tab-a' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'site_redirects', $r->get_error_code() );
		$this->assertSame( array(), $this->credits->calls );
		$this->assertNull( $this->store->state( 'run' ) );
	}

	public function test_cancel_refused_as_unknown_by_cloud_clears_the_record(): void {
		$this->credits->answers['cancel'] = array( EMCP_Test_Static_Site_Credits::answer( 'refused', 404, 'not_found' ) );
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$out  = $r->cancel( $view['generation'], 'tab-a', 0 );
		$this->assertSame( 'cancelled', $out['phase'] );
		$this->assertFalse( $out['refunded'] );
		$this->assertNull( $this->store->state( 'run' ) );
		$this->assertSame( array(), $this->lease_store->rows );
		$this->assertIsArray( $r->start( 'full', array(), 'tab-a' ) );
	}

	public function test_cancel_refused_for_another_reason_keeps_the_record(): void {
		$this->credits->answers['cancel'] = array( EMCP_Test_Static_Site_Credits::answer( 'refused', 409, 'something_else' ) );
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$r->cancel( $view['generation'], 'tab-a', 0 );
		$this->assertSame( 'cancelling', $this->store->state( 'run' )['cloud'] );
	}

	public function test_linked_password_protected_page_is_never_fetched_or_written(): void {
		$r    = $this->runner( array( 'gone' => static fn( string $url ) => 'https://example.test/about/' === $url ) );
		$view = $r->start( 'full', array(), 'tab-a' );
		$this->assertSame( 'done', $this->drive( $r, $view, 'tab-a' )['phase'] );
		$this->assertNotContains( 'https://example.test/about/', $this->web->calls );
		$dir = $this->base . '/' . $this->store->state( 'current' )['dir'];
		$this->assertFileDoesNotExist( $dir . '/serve/about/index.html' );
		$this->assertFileDoesNotExist( $dir . '/build/about/index.html' );
	}

	public function test_a_late_step_reports_how_the_generation_really_ended(): void {
		$r    = $this->runner();
		$view = $r->start( 'full', array(), 'tab-a' );
		$out  = $r->cancel( $view['generation'], 'tab-a', 0 );
		$this->assertSame( 'cancelled', $out['phase'] );
		$late = $r->step( $view['generation'], 'tab-a', 0 );
		$this->assertSame( 'cancelled', $late['phase'] );
		$this->assertSame( 'none', $r->step( '00000000-0000-4000-8000-0000000000ff', 'tab-a', 0 )['phase'] );
		$done = $this->drive( $r, $r->start( 'full', array(), 'tab-a' ), 'tab-a' );
		$this->assertSame( 'done', $done['phase'] );
		$this->assertSame( 'done', $r->step( $done['generation'], 'tab-a', 0 )['phase'] );
	}
}
