<?php
/**
 * The Static Site run (spec 3, 4.4): phases, the run record, the Cloud call
 * protocol, cancel, takeover and promotion. One step runs one phase for the
 * time budget; the screen calls again until the run is done.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Runner {

	const LEASE      = 'static-site-build';
	const TTL        = 120;
	const MAX_URLS   = 20000;
	const BUILD_CAP  = 2147483648;
	const MAX_ROUNDS = 5;
	const DAY        = 86400;

	/** @var EMCP_Tools_Static_Site_Storage */
	private $store;
	/** @var EMCP_Tools_Static_Site_Credit_Api */
	private $credits;
	/** @var EMCP_Tools_Lease */
	private $lease;
	/** @var string */
	private $base;
	/** @var string */
	private $site;
	/** @var string */
	private $uuid;
	/** @var array */
	private $settings;
	/** @var EMCP_Tools_Static_Site_Paths */
	private $paths;
	/** @var array<string,callable> */
	private $fn;
	/** @var float */
	private $budget;

	public function __construct( array $deps ) {
		$this->store    = $deps['store'];
		$this->credits  = $deps['credits'];
		$this->lease    = $deps['lease'] ?? new EMCP_Tools_Lease();
		$this->base     = rtrim( str_replace( '\\', '/', (string) $deps['base_dir'] ), '/' );
		$this->site     = rtrim( (string) $deps['site_url'], '/' );
		$this->uuid     = (string) $deps['site_uuid'];
		$this->settings = (array) $deps['settings'];
		$this->budget   = (float) ( $deps['budget'] ?? self::budget() );
		$this->paths    = new EMCP_Tools_Static_Site_Paths( $this->site );
		$this->fn       = array(
			'fetch'     => $deps['fetch'] ?? static fn( EMCP_Tools_Static_Site_Paths $p, array $exclude, string $key, bool $verify ) => new EMCP_Tools_Static_Site_Fetcher( $p, $exclude, $key, $verify ),
			'discover'  => $deps['discover'] ?? array( 'EMCP_Tools_Static_Site_Discovery', 'collect' ),
			'preflight' => $deps['preflight'] ?? array( $this, 'preflight' ),
			'reach'     => $deps['reach'] ?? array( $this, 'reachable' ),
			'gone'      => $deps['gone'] ?? array( $this, 'page_gone' ),
			'log'       => $deps['log'] ?? array( $this, 'log' ),
		);
	}

	/** Production wiring. */
	public static function make(): self {
		return new self(
			array(
				'store'     => new EMCP_Tools_Static_Site_Store(),
				'credits'   => new EMCP_Tools_Static_Site_Credits(),
				'lease'     => new EMCP_Tools_Lease(),
				'base_dir'  => EMCP_Tools_Static_Site_Module::base_dir(),
				'site_url'  => home_url( '/' ),
				'site_uuid' => EMCP_Tools_Cloud::site_uuid(),
				'settings'  => EMCP_Tools_Static_Site_Module::settings(),
			)
		);
	}

	public static function budget(): float {
		$max = (int) ini_get( 'max_execution_time' );
		return $max <= 0 ? 20.0 : (float) max( 5, min( 20, $max - 5 ) );
	}

	private static function error( string $code, int $status = 409 ): WP_Error {
		$messages = array(
			'busy'                   => __( 'Another request is working on this generation. Trying again shortly.', 'emcp-tools' ),
			'run_exists'             => __( 'A generation is already open on this site.', 'emcp-tools' ),
			'stale'                  => __( 'This generation has moved on in another tab.', 'emcp-tools' ),
			'elsewhere'              => __( 'This generation is running in another tab.', 'emcp-tools' ),
			'already_promoted'       => __( 'This generation is already live and can no longer be cancelled.', 'emcp-tools' ),
			'not_connected'          => __( 'Connect this site to EMCP Cloud first.', 'emcp-tools' ),
			'allowance_exhausted'    => __( 'You have no static-site generations left.', 'emcp-tools' ),
			'site_not_registered'    => __( 'This site is not connected to your Cloud account any more. Reconnect it on the Connection screen.', 'emcp-tools' ),
			'generation_active'      => __( 'Another generation for this site is still open in Cloud.', 'emcp-tools' ),
			'plugin_outdated'        => __( 'Update EMCP Tools to generate static sites.', 'emcp-tools' ),
			'static_sites_disabled'  => __( 'Static-site generation is not available on your Cloud account yet.', 'emcp-tools' ),
			'unauthorized'           => __( 'EMCP Cloud did not accept this site\'s connection. Reconnect it on the Connection screen.', 'emcp-tools' ),
		);
		return new WP_Error( $code, $messages[ $code ] ?? __( 'EMCP Cloud refused this generation.', 'emcp-tools' ), array( 'status' => $status ) );
	}

	private function site_lock(): string {
		return EMCP_Tools_Static_Site_Locks::site();
	}

	private function step_lock( string $gen ): string {
		return EMCP_Tools_Static_Site_Locks::step( $gen );
	}

	private function save( array $old, ?array $new ): bool {
		return $this->store->put_state( 'run', $old['_raw'] ?? null, $new );
	}

	private function dir( array $run ): string {
		return $this->base . '/' . $run['dir'];
	}

	private function root( array $run ): string {
		return 'local' === $run['settings']['delivery'] ? $this->dir( $run ) . '/local/' . $run['settings']['local_folder'] : $this->dir( $run ) . '/build';
	}

	// ------------------------------------------------------------------
	// Start, reserve, resolve
	// ------------------------------------------------------------------

	public function start( string $mode, array $urls, string $owner ) {
		$mode = 'pages' === $mode ? 'pages' : 'full';
		// Before anything is reserved and outside the site lock: a run that
		// cannot reach this site would spend a generation and export nothing.
		$reach = ( $this->fn['reach'] )();
		if ( is_wp_error( $reach ) ) {
			return $reach;
		}
		$made = EMCP_Tools_Static_Site_Locks::with(
			array( $this->site_lock() ),
			function () use ( $mode, $urls, $owner ) {
				if ( null !== $this->store->state( 'run' ) ) {
					return self::error( 'run_exists' );
				}
				$ok = ( $this->fn['preflight'] )( $mode );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				if ( ! $this->lease->acquire( self::LEASE, $owner, self::TTL ) ) {
					return self::error( 'elsewhere' );
				}
				$run = array(
					'generation' => wp_generate_uuid4(),
					'dir'        => 'gen-' . EMCP_Tools_Static_Site_Files::random_hex( 16 ),
					'request_id' => wp_generate_uuid4(),
					'token'      => EMCP_Tools_Static_Site_Files::random_hex( 32 ),
					'crawl_key'  => EMCP_Tools_Static_Site_Files::random_hex( 16 ),
					'cloud_id'   => '',
					'cloud'      => 'reserving',
					'owner'      => $owner,
					'epoch'      => 0,
					'mode'       => $mode,
					'urls'       => array_values( array_map( 'strval', $urls ) ),
					'phase'      => 'reserve',
					'cursor'     => array( 'source' => 'home', 'offset' => 0 ),
					'settings'   => $this->settings,
					'stats'      => array( 'bytes' => 0, 'errors' => 0 ),
					'rounds'     => 0,
					'zip'        => '',
					'error'      => '',
					'started_at' => time(),
					'since'      => time(),
				);
				if ( ! $this->store->put_state( 'run', null, $run ) ) {
					$this->lease->release( self::LEASE, $owner );
					return self::error( 'run_exists' );
				}
				return $this->store->state( 'run' );
			}
		);
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		return $this->reserve_call( $made, true );
	}

	/**
	 * Reserve with the record's identity and apply the answer by compare-and-set.
	 *
	 * @param bool $first True only for the call made right after the record was created.
	 */
	private function reserve_call( array $run, bool $first ) {
		$out    = $this->credits->reserve( $run['request_id'], hash( 'sha256', $run['token'] ), $run['mode'], $this->uuid );
		$result = $this->apply(
			$run,
			function ( array $now ) use ( $out, $first ) {
				if ( 'ok' === $out['kind'] ) {
					$now['cloud_id'] = (string) ( $out['data']['id'] ?? '' );
					if ( 'cancelling' === $now['cloud'] ) {
						return $now;
					}
					if ( ! in_array( $now['cloud'], array( 'reserving', 'uncertain' ), true ) ) {
						return false;
					}
					$now['cloud'] = 'reserved';
					$now['phase'] = 'setup';
					return $now;
				}
				if ( 'voided' === $out['kind'] || ( 'refused' === $out['kind'] && $first ) ) {
					return null;
				}
				if ( 'cancelling' !== $now['cloud'] ) {
					$now['cloud'] = 'uncertain';
				}
				return $now;
			}
		);
		if ( 'gone' === $result && 'ok' === $out['kind'] ) {
			// Only after a void receipt or a completed cancel: an idempotent safety net.
			$this->credits->void( $run['request_id'], $run['token'] );
			( $this->fn['log'] )( 'static_site_orphan_voided' );
		}
		if ( 'applied:deleted' === $result ) {
			$this->lease->release( self::LEASE, $run['owner'] );
			EMCP_Tools_Static_Site_Credits_Cache::forget();
			if ( 'refused' === $out['kind'] ) {
				return self::error( $out['code'], $out['status'] ?: 409 );
			}
			return array( 'phase' => 'none' );
		}
		$now = $this->store->state( 'run' );
		if ( null !== $now && 'cancelling' === $now['cloud'] && '' !== $now['cloud_id'] && ! $first ) {
			return $this->cancel_call( $now );
		}
		return $this->view();
	}

	/** Retry whatever Cloud call the record is waiting on, with the same identity. */
	public function resolve( string $gen ) {
		$run = $this->store->state( 'run' );
		if ( null === $run || $run['generation'] !== $gen ) {
			return array( 'phase' => 'none' );
		}
		if ( in_array( $run['cloud'], array( 'reserving', 'uncertain' ), true ) ) {
			return $this->reserve_call( $run, false );
		}
		if ( 'cancelling' === $run['cloud'] ) {
			return $this->cancel_call( $run );
		}
		if ( $this->promoted( $run ) || 'completing' === $run['cloud'] ) {
			return $this->complete_call( $run );
		}
		return $this->view();
	}

	/**
	 * Apply $mutate under site then step locks when the record is still the one
	 * the call was made for. $mutate returns the new record, null to delete it,
	 * or false to leave it.
	 *
	 * @return string applied|applied:deleted|kept|gone|stale|raced|busy
	 */
	private function apply( array $seen, callable $mutate ): string {
		$r = EMCP_Tools_Static_Site_Locks::with(
			array( $this->site_lock(), $this->step_lock( $seen['generation'] ) ),
			function () use ( $seen, $mutate ) {
				$run = $this->store->state( 'run' );
				if ( null === $run || $run['request_id'] !== $seen['request_id'] ) {
					return 'gone';
				}
				if ( (int) $run['epoch'] !== (int) $seen['epoch'] ) {
					return 'stale';
				}
				$next = $mutate( $run );
				if ( false === $next ) {
					return 'kept';
				}
				if ( ! $this->save( $run, $next ) ) {
					return 'raced';
				}
				return null === $next ? 'applied:deleted' : 'applied';
			}
		);
		return is_wp_error( $r ) ? 'busy' : (string) $r;
	}

	// ------------------------------------------------------------------
	// Steps
	// ------------------------------------------------------------------

	private function check( ?array $run, string $gen, string $owner, int $epoch ) {
		if ( null === $run || $run['generation'] !== $gen || (int) $run['epoch'] !== $epoch ) {
			return self::error( 'stale' );
		}
		if ( $run['owner'] !== $owner || ! $this->lease->renew( self::LEASE, $owner, self::TTL ) ) {
			return self::error( 'elsewhere' );
		}
		return true;
	}

	private function promoted( array $run ): bool {
		$current = $this->store->state( 'current' );
		return is_array( $current ) && ( $current['dir'] ?? '' ) === $run['dir'];
	}

	public function step( string $gen, string $owner, int $epoch ) {
		$run = $this->store->state( 'run' );
		if ( null === $run ) {
			return array( 'phase' => 'done', 'generation' => $gen );
		}
		if ( $this->promoted( $run ) || 'complete' === $run['phase'] ) {
			$ok = $this->check( $run, $gen, $owner, $epoch );
			return is_wp_error( $ok ) ? $ok : $this->complete_call( $run );
		}
		if ( 'start' === $run['phase'] ) {
			return $this->start_call( $gen, $owner, $epoch );
		}
		if ( 'promote' === $run['phase'] ) {
			return $this->promote_step( $gen, $owner, $epoch );
		}
		$out = EMCP_Tools_Static_Site_Locks::with(
			array( $this->step_lock( $gen ) ),
			function () use ( $gen, $owner, $epoch ) {
				$run = $this->store->state( 'run' );
				$ok  = $this->check( $run, $gen, $owner, $epoch );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				if ( ! in_array( $run['cloud'], array( 'reserved', 'running' ), true ) ) {
					return $this->view();
				}
				$deadline = microtime( true ) + $this->budget;
				$next     = $run;
				try {
					switch ( $run['phase'] ) {
						case 'setup':
							$next = $this->phase_setup( $run );
							break;
						case 'discover':
							$next = $this->phase_discover( $run, $deadline );
							break;
						case 'fetch':
							$next = $this->phase_fetch( $run, $deadline );
							break;
						case 'rewrite':
							$next = $this->phase_rewrite( $run, $deadline );
							break;
						case 'package':
							$next = $this->phase_package( $run, $deadline );
							break;
						case 'recheck':
							$next = $this->phase_recheck( $run, $deadline );
							break;
					}
				} catch ( Throwable $e ) {
					$next = $this->failed( $run, 'build_too_large' === $e->getMessage() ? 'build_too_large' : 'write_failed' );
				}
				if ( ! $this->save( $run, $next ) ) {
					return self::error( 'stale' );
				}
				return 'cancelling' === $next['cloud'] ? 'cancel' : $this->view();
			}
		);
		if ( 'cancel' === $out ) {
			return $this->cancel_call( $this->store->state( 'run' ) );
		}
		return $out;
	}

	private function failed( array $run, string $code ): array {
		EMCP_Tools_Static_Site_Files::remove_tree( $this->dir( $run ) );
		$run['cloud'] = 'cancelling';
		$run['phase'] = 'cancelled';
		$run['error'] = $code;
		return $run;
	}

	private function start_call( string $gen, string $owner, int $epoch ) {
		$run = EMCP_Tools_Static_Site_Locks::with(
			array( $this->step_lock( $gen ) ),
			function () use ( $gen, $owner, $epoch ) {
				$run = $this->store->state( 'run' );
				$ok  = $this->check( $run, $gen, $owner, $epoch );
				return is_wp_error( $ok ) ? $ok : $run;
			}
		);
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$out = $this->credits->start( $run['cloud_id'], $run['token'] );
		$res = $this->apply(
			$run,
			function ( array $now ) use ( $out ) {
				if ( 'start' !== $now['phase'] ) {
					return false;
				}
				if ( 'ok' === $out['kind'] ) {
					$now['cloud'] = 'running';
					$now['phase'] = 'fetch';
					return $now;
				}
				if ( 'refused' === $out['kind'] ) {
					return $this->failed( $now, $out['code'] );
				}
				return false;
			}
		);
		$now = $this->store->state( 'run' );
		if ( 'applied' === $res && null !== $now && 'cancelling' === $now['cloud'] ) {
			return $this->cancel_call( $now );
		}
		return $this->view();
	}

	private function phase_setup( array $run ): array {
		EMCP_Tools_Static_Site_Files::protect_base( $this->base );
		foreach ( array( 'serve', 'zips' ) as $sub ) {
			EMCP_Tools_Static_Site_Files::ensure_dir( $this->dir( $run ) . '/' . $sub );
		}
		EMCP_Tools_Static_Site_Files::ensure_dir( $this->root( $run ) );
		$this->store->journal_ack_all( $run['generation'] );
		if ( 'pages' === $run['mode'] ) {
			foreach ( $run['urls'] as $raw ) {
				$this->enqueue( $run, $raw, '' );
			}
			$run['phase'] = 'start';
		} else {
			$run['phase'] = 'discover';
		}
		return $run;
	}

	/** Normalise, filter and insert one discovered URL. */
	private function enqueue( array $run, string $raw, string $from ): void {
		$url = $this->paths->normalize( $raw, $from );
		if ( '' === $url || ! $this->paths->is_same_origin( $url ) || $this->paths->is_excluded( $url, $this->exclusions( $run ) ) ) {
			return;
		}
		$status = $this->paths->is_page( $url ) && $this->paths->has_query( $url ) ? 'skipped' : 'pending';
		$this->store->add_url( $run['generation'], $url, $status );
	}

	private function exclusions( array $run ): array {
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $run['settings']['exclude_urls'] );
		$lines[] = '/wp-admin/*';
		$lines[] = '/wp-json/*';
		$lines[] = '/wp-login.php*';
		return $lines;
	}

	private function phase_discover( array $run, float $deadline ): array {
		while ( null !== $run['cursor'] && microtime( true ) < $deadline ) {
			$batch = ( $this->fn['discover'] )( $run['cursor'], $run['settings'] );
			foreach ( $batch['urls'] as $url ) {
				$this->enqueue( $run, (string) $url, '' );
			}
			$run['cursor'] = $batch['cursor'];
			if ( array_sum( $this->store->counts( $run['generation'] ) ) > self::MAX_URLS ) {
				return $this->failed( $run, 'too_many_urls' );
			}
		}
		if ( null === $run['cursor'] ) {
			$run['phase'] = 'start';
		}
		return $run;
	}

	private function phase_fetch( array $run, float $deadline ): array {
		$gen      = $run['generation'];
		$fetcher  = ( $this->fn['fetch'] )( $this->paths, $this->exclusions( $run ), $run['crawl_key'], ! $run['settings']['accept_self_signed'] );
		$registry = new EMCP_Tools_Static_Site_Registry( $this->store->taken_paths( $gen ) );
		foreach ( $this->store->rows( $gen, array( 'pending' ), (int) $run['settings']['batch_size'] ) as $row ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$res = $fetcher->fetch( $row['url'], $deadline );
			if ( 'no_time' === $res['error'] ) {
				break;
			}
			if ( ! $res['ok'] ) {
				$this->store->update_row( $row['id'], array( 'status' => 'error', 'error' => $res['error'], 'http_code' => $res['code'] ) );
				$run['stats']['errors']++;
				continue;
			}
			// The URL decides page or asset; a page is only written when the response
			// really is HTML. A feed or plain-text endpoint at a page-shaped URL would
			// otherwise be served later as text/html with content escaped only for
			// XML or JSON.
			$html = $this->paths->is_page( $row['url'] );
			if ( $html && ! str_contains( $res['type'], 'text/html' ) ) {
				$this->store->update_row( $row['id'], array( 'status' => 'skipped', 'error' => 'not_html', 'http_code' => $res['code'] ) );
				continue;
			}
			$path = $html ? $this->paths->page_path( $row['url'] ) : $this->paths->output_path( $row['url'], $registry );
			$size = EMCP_Tools_Static_Site_Files::write( EMCP_Tools_Static_Site_Paths::confine( $this->root( $run ), $path ), $res['body'] );
			if ( $html ) {
				$size += EMCP_Tools_Static_Site_Files::write( EMCP_Tools_Static_Site_Paths::confine( $this->dir( $run ) . '/serve', $path ), $res['body'] );
			}
			$run['stats']['bytes'] += $size;
			if ( $run['stats']['bytes'] > self::BUILD_CAP ) {
				throw new RuntimeException( 'build_too_large' );
			}
			// An asset URL that answered HTML is stored as an opaque file, never rewritten as a page.
			$type = $html ? 'text/html' : ( str_contains( $res['type'], 'text/html' ) ? 'application/octet-stream' : $res['type'] );
			$this->store->update_row( $row['id'], array( 'status' => 'fetched', 'file_path' => $path, 'content_type' => $type, 'content_hash' => md5( $res['body'] ), 'bytes' => strlen( $res['body'] ), 'http_code' => $res['code'] ) );
			if ( $html ) {
				foreach ( EMCP_Tools_Static_Site_Extractor::from_html( $res['body'], 'pages' === $run['mode'], $this->site ) as $raw ) {
					$this->enqueue( $run, $raw, $row['url'] );
				}
			} elseif ( str_contains( $type, 'text/css' ) ) {
				foreach ( EMCP_Tools_Static_Site_Extractor::from_css( $res['body'] ) as $raw ) {
					$this->enqueue( $run, $raw, $row['url'] );
				}
			}
		}
		if ( array_sum( $this->store->counts( $gen ) ) > self::MAX_URLS ) {
			return $this->failed( $run, 'too_many_urls' );
		}
		if ( ! $this->store->rows( $gen, array( 'pending' ), 1 ) ) {
			$run['phase'] = 'rewrite';
		}
		return $run;
	}

	private function phase_rewrite( array $run, float $deadline ): array {
		$gen      = $run['generation'];
		$manifest = $this->store->manifest( $gen );
		$rewriter = new EMCP_Tools_Static_Site_Rewriter(
			$this->paths,
			$this->site,
			static fn( string $url ) => $manifest[ EMCP_Tools_Static_Site_Paths::url_hash( $url ) ] ?? null,
			(string) $run['settings']['url_style'],
			(string) $run['settings']['base_url']
		);
		foreach ( $this->store->rows( $gen, array( 'fetched' ), (int) $run['settings']['batch_size'] ) as $row ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$file = EMCP_Tools_Static_Site_Paths::confine( $this->root( $run ), (string) $row['file_path'] );
			$type = (string) $row['content_type'];
			if ( is_file( $file ) && ( str_contains( $type, 'text/html' ) || str_contains( $type, 'text/css' ) ) ) {
				$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$body = str_contains( $type, 'text/html' ) ? $rewriter->html( $body, $row['url'], $row['file_path'] ) : $rewriter->css( $body, $row['url'], $row['file_path'] );
				EMCP_Tools_Static_Site_Files::write( $file, $body );
			}
			$this->store->update_row( $row['id'], array( 'status' => 'saved' ) );
		}
		if ( ! $this->store->rows( $gen, array( 'fetched' ), 1 ) ) {
			$run['phase'] = 'package';
		}
		return $run;
	}

	private function phase_package( array $run, float $deadline ): array {
		$gen = $run['generation'];
		if ( 'local' === $run['settings']['delivery'] ) {
			$this->store->set_status( $gen, 'saved', 'zipped' );
			$run['phase'] = 'recheck';
			return $run;
		}
		if ( '' === $run['zip'] ) {
			$run['zip'] = 'site-' . gmdate( 'Ymd-His' ) . '-' . EMCP_Tools_Static_Site_Files::random_hex( 8 ) . '.zip';
		}
		$rows = $this->store->rows( $gen, array( 'saved' ), 500 );
		if ( $rows ) {
			$store = $this->store;
			$res   = EMCP_Tools_Static_Site_Packager::zip_batch(
				$this->dir( $run ) . '/zips/' . $run['zip'],
				$this->root( $run ),
				$rows,
				static function ( array $zipped, array $missing ) use ( $store ) {
					foreach ( $zipped as $id ) {
						$store->update_row( $id, array( 'status' => 'zipped' ) );
					}
					foreach ( $missing as $id ) {
						$store->update_row( $id, array( 'status' => 'error', 'error' => 'missing_file' ) );
					}
				},
				$deadline
			);
			if ( 'zip_open_failed' === $res['error'] ) {
				return $this->failed( $run, 'zip_failed' );
			}
		}
		if ( ! $this->store->rows( $gen, array( 'saved' ), 1 ) ) {
			$run['phase'] = 'recheck';
		}
		return $run;
	}

	private function phase_recheck( array $run, float $deadline ): array {
		$gen     = $run['generation'];
		$pending = $this->store->journal_pending( 200 );
		if ( ! $pending ) {
			$run['phase'] = 'promote';
			return $run;
		}
		$run['rounds']++;
		if ( $run['rounds'] > self::MAX_ROUNDS ) {
			return $this->failed( $run, 'site_changing' );
		}
		$fetcher = ( $this->fn['fetch'] )( $this->paths, $this->exclusions( $run ), $run['crawl_key'], ! $run['settings']['accept_self_signed'] );
		$changed = false;
		$done    = array();
		foreach ( $pending as $entry ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$done[] = $entry['id'];
			if ( '*' === $entry['url'] ) {
				$this->store->set_status( $gen, 'zipped', 'pending', true );
				$changed = true;
				continue;
			}
			$row = $this->store->find( $gen, $this->paths->normalize( $entry['url'] ) );
			if ( null === $row || ! str_contains( (string) $row['content_type'], 'text/html' ) || null === $row['file_path'] ) {
				continue;
			}
			$serve = EMCP_Tools_Static_Site_Paths::confine( $this->dir( $run ) . '/serve', $row['file_path'] );
			$build = EMCP_Tools_Static_Site_Paths::confine( $this->root( $run ), $row['file_path'] );
			$res   = $fetcher->fetch( $row['url'], $deadline );
			if ( ( $this->fn['gone'] )( $row['url'] ) || ! $res['ok'] || ! str_contains( $res['type'], 'text/html' ) ) {
				@unlink( $serve ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $build ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$this->store->update_row( $row['id'], array( 'status' => 'error', 'error' => 'removed' ) );
				$run['stats']['errors']++;
				$changed = true;
				continue;
			}
			if ( md5( $res['body'] ) !== $row['content_hash'] ) {
				EMCP_Tools_Static_Site_Files::write( $serve, $res['body'] );
				EMCP_Tools_Static_Site_Files::write( $build, $res['body'] );
				$this->store->update_row( $row['id'], array( 'status' => 'fetched', 'content_hash' => md5( $res['body'] ) ) );
				$changed = true;
			}
		}
		$this->store->journal_ack( $done, $gen );
		if ( $changed ) {
			if ( '' !== $run['zip'] ) {
				@unlink( $this->dir( $run ) . '/zips/' . $run['zip'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$run['zip'] = '';
			}
			$this->store->set_status( $gen, 'zipped', 'saved' );
			$run['phase'] = $this->store->rows( $gen, array( 'pending' ), 1 ) ? 'fetch' : 'rewrite';
		}
		return $run;
	}

	private function promote_step( string $gen, string $owner, int $epoch ) {
		$out = EMCP_Tools_Static_Site_Locks::with(
			array( $this->site_lock(), $this->step_lock( $gen ) ),
			function () use ( $gen, $owner, $epoch ) {
				$run = $this->store->state( 'run' );
				$ok  = $this->check( $run, $gen, $owner, $epoch );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$counts  = $this->store->counts( $gen );
				$current = array(
					'generation' => $gen,
					'dir'        => $run['dir'],
					'cloud_id'   => $run['cloud_id'],
					'date'       => gmdate( 'c' ),
					'mode'       => $run['mode'],
					'delivery'   => $run['settings']['delivery'],
					'local'      => 'local' === $run['settings']['delivery'] ? $this->root( $run ) : '',
					'zip'        => $run['zip'],
					'files'      => (int) ( $counts['zipped'] ?? 0 ),
					'pages'      => $this->html_count( $gen ),
					'bytes'      => (int) $run['stats']['bytes'],
					'errors'     => (int) $run['stats']['errors'],
					'duration'   => time() - (int) $run['started_at'],
				);
				if ( ! $this->store->promote( $current ) ) {
					$run['phase'] = 'recheck';
				} else {
					$run['phase'] = 'complete';
					$run['cloud'] = 'completing';
					$run['since'] = time();
				}
				return $this->save( $run, $run ) ? 'saved' : self::error( 'stale' );
			}
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$run = $this->store->state( 'run' );
		return null !== $run && 'complete' === $run['phase'] ? $this->complete_call( $run ) : $this->view();
	}

	private function html_count( string $gen ): int {
		$n = 0;
		foreach ( $this->store->rows( $gen, array( 'zipped' ), self::MAX_URLS ) as $row ) {
			if ( str_contains( (string) $row['content_type'], 'text/html' ) ) {
				$n++;
			}
		}
		return $n;
	}

	private function complete_call( array $run ) {
		$current = (array) $this->store->state( 'current' );
		$out     = $this->credits->complete(
			$run['cloud_id'],
			$run['token'],
			array(
				'pages'            => (int) ( $current['pages'] ?? 0 ),
				'files'            => (int) ( $current['files'] ?? 0 ),
				'bytes'            => (int) ( $current['bytes'] ?? 0 ),
				'duration_seconds' => (int) ( $current['duration'] ?? 0 ),
				'errors'           => (int) ( $current['errors'] ?? 0 ),
				'delivery'         => 'local' === ( $current['delivery'] ?? 'zip' ) ? 'local' : 'zip',
			)
		);
		$lost = 'ok' !== $out['kind'] && time() - (int) $run['since'] > self::DAY;
		$res  = $this->apply(
			$run,
			static fn( array $now ) => ( 'ok' === $out['kind'] || $lost ) ? null : false
		);
		if ( 'applied:deleted' === $res ) {
			$this->lease->release( self::LEASE, $run['owner'] );
			EMCP_Tools_Static_Site_Credits_Cache::forget();
			$this->store->journal_prune( $run['generation'] );
			if ( $lost ) {
				( $this->fn['log'] )( 'static_site_completion_lost' );
			}
			return array( 'phase' => 'done', 'generation' => $run['generation'] );
		}
		return $this->view();
	}

	// ------------------------------------------------------------------
	// Cancel and takeover
	// ------------------------------------------------------------------

	public function cancel( string $gen, string $owner, int $epoch ) {
		$run = EMCP_Tools_Static_Site_Locks::with(
			array( $this->site_lock(), $this->step_lock( $gen ) ),
			function () use ( $gen, $owner, $epoch ) {
				$run = $this->store->state( 'run' );
				if ( null === $run || $run['generation'] !== $gen ) {
					return self::error( 'stale' );
				}
				$lease_free = ! $this->lease->active( self::LEASE );
				if ( ! $lease_free && ( $run['owner'] !== $owner || (int) $run['epoch'] !== $epoch ) ) {
					return self::error( 'elsewhere' );
				}
				if ( $this->promoted( $run ) ) {
					return self::error( 'already_promoted' );
				}
				$next = $this->failed( $run, 'cancelled' );
				return $this->save( $run, $next ) ? $this->store->state( 'run' ) : self::error( 'stale' );
			}
		);
		return is_wp_error( $run ) ? $run : $this->cancel_call( $run );
	}

	private function cancel_call( array $run ) {
		$out = '' !== $run['cloud_id'] ? $this->credits->cancel( $run['cloud_id'], $run['token'] ) : $this->credits->void( $run['request_id'], $run['token'] );
		if ( in_array( $out['kind'], array( 'ok', 'voided' ), true ) ) {
			$res = $this->apply( $run, static fn( array $now ) => 'cancelling' === $now['cloud'] ? null : false );
			if ( 'applied:deleted' === $res ) {
				$this->store->drop_rows( $run['generation'] );
				$this->lease->release( self::LEASE, $run['owner'] );
				EMCP_Tools_Static_Site_Credits_Cache::forget();
				update_option(
					'emcp_tools_static_last_cancel',
					array( 'generation' => $run['generation'], 'refunded' => ! empty( $out['data']['refunded'] ), 'error' => $run['error'], 'at' => time() ),
					false
				);
				return array( 'phase' => 'cancelled', 'generation' => $run['generation'], 'error' => $run['error'], 'refunded' => ! empty( $out['data']['refunded'] ) );
			}
		}
		return $this->view();
	}

	public function takeover( string $gen, string $owner ) {
		return EMCP_Tools_Static_Site_Locks::with(
			array( $this->site_lock(), $this->step_lock( $gen ) ),
			function () use ( $gen, $owner ) {
				$run = $this->store->state( 'run' );
				if ( null === $run || $run['generation'] !== $gen ) {
					return self::error( 'stale' );
				}
				if ( $this->lease->active( self::LEASE ) || ! $this->lease->acquire( self::LEASE, $owner, self::TTL ) ) {
					return self::error( 'elsewhere' );
				}
				$next          = $run;
				$next['epoch'] = (int) $run['epoch'] + 1;
				$next['owner'] = $owner;
				if ( ! $this->save( $run, $next ) ) {
					$this->lease->release( self::LEASE, $owner );
					return self::error( 'stale' );
				}
				return $this->view();
			}
		);
	}

	// ------------------------------------------------------------------
	// View and defaults
	// ------------------------------------------------------------------

	public function view(): ?array {
		$run = $this->store->state( 'run' );
		if ( null === $run ) {
			return null;
		}
		return array(
			'generation'   => $run['generation'],
			'mode'         => $run['mode'],
			'phase'        => $run['phase'],
			'cloud'        => $run['cloud'],
			'owner'        => $run['owner'],
			'epoch'        => (int) $run['epoch'],
			'counts'       => $this->store->counts( $run['generation'] ),
			'error'        => (string) $run['error'],
			'started_at'   => (int) $run['started_at'],
			'promoted'     => $this->promoted( $run ),
			'lease_active' => $this->lease->active( self::LEASE ),
		);
	}

	/** @return true|WP_Error */
	public function preflight( string $mode ) {
		if ( is_multisite() ) {
			return new WP_Error( 'multisite_not_supported', __( 'Static sites are not available on WordPress multisite.', 'emcp-tools' ), array( 'status' => 409 ) );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return new WP_Error( 'plain_permalinks', __( 'Switch Settings, Permalinks to any option other than Plain first.', 'emcp-tools' ), array( 'status' => 409 ) );
		}
		if ( 'zip' === $this->settings['delivery'] && ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_unavailable', __( 'This server cannot create ZIP files. Choose the folder delivery instead.', 'emcp-tools' ), array( 'status' => 409 ) );
		}
		if ( ! EMCP_Tools_Static_Site_Files::ensure_dir( $this->base ) || ! is_writable( $this->base ) ) {
			return new WP_Error( 'not_writable', __( 'WordPress cannot write to wp-content/emcp-static.', 'emcp-tools' ), array( 'status' => 409 ) );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $this->base ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_numeric( $free ) && $free < 268435456 ) {
			return new WP_Error( 'low_disk_space', __( 'Less than 256 MB of disk space is free.', 'emcp-tools' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Fetch the home page once, the way the crawler will. Only a transport
	 * failure refuses: an HTTP error on the home page still lets the run
	 * export the pages that work.
	 *
	 * @return true|WP_Error
	 */
	public function reachable() {
		$verify  = empty( $this->settings['accept_self_signed'] );
		$fetcher = ( $this->fn['fetch'] )( new EMCP_Tools_Static_Site_Paths( $this->site ), array(), EMCP_Tools_Static_Site_Files::random_hex( 16 ), $verify );
		$res     = $fetcher->fetch( $this->site . '/', microtime( true ) + 15 );
		if ( ! empty( $res['ok'] ) || 'transport' !== ( $res['error'] ?? '' ) ) {
			return true;
		}
		$message = __( 'WordPress could not load this site\'s home page from the server, so nothing was reserved. Check that the site answers at its own address from the server.', 'emcp-tools' );
		if ( $verify && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			$message = __( 'WordPress could not load this site\'s home page, so nothing was reserved. Local sites often use a self-signed certificate: turn on "Accept this site\'s self-signed certificate" in Settings and try again.', 'emcp-tools' );
		}
		return new WP_Error( 'site_unreachable', $message, array( 'status' => 409 ) );
	}

	/** A page that must not be served: password, unpublished, or gone. */
	public function page_gone( string $url ): bool {
		$id = url_to_postid( $url );
		if ( ! $id ) {
			return false;
		}
		$post = get_post( $id );
		return ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password;
	}

	public function log( string $reason ): void {
		if ( class_exists( 'EMCP_Tools_MCP_Request_Log' ) ) {
			EMCP_Tools_MCP_Request_Log::record( array( 'method' => 'static-site', 'tool' => 'cloud', 'status' => 'error', 'stage' => 'cloud', 'failure_reason' => $reason, 'client' => 'Admin' ) );
		}
	}
}

/** Allowance cache invalidation, a no-op where transients are unavailable (unit tests). */
final class EMCP_Tools_Static_Site_Credits_Cache {
	public static function forget(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( EMCP_Tools_Static_Site_Credits::ALLOWANCE_CACHE );
		}
	}
}
