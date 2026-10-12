<?php
/**
 * Front-end static serving and purge hooks (spec 4.8).
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Server {

	/** @var EMCP_Tools_Static_Site_Storage */
	private $store;
	/** @var EMCP_Tools_Static_Site_Paths */
	private $paths;
	/** @var string */
	private $base;
	/** @var array */
	private $settings;
	/** @var bool */
	private $verified;

	public function __construct( EMCP_Tools_Static_Site_Storage $store, EMCP_Tools_Static_Site_Paths $paths, string $base_dir, array $settings, bool $deny_verified ) {
		$this->store    = $store;
		$this->paths    = $paths;
		$this->base     = rtrim( str_replace( '\\', '/', $base_dir ), '/' );
		$this->settings = $settings;
		$this->verified = $deny_verified;
	}

	public function register(): void {
		if ( ! empty( $this->settings['serve_enabled'] ) && $this->verified ) {
			add_action( 'template_redirect', array( $this, 'maybe_serve' ), -1 );
		}
		add_action( 'save_post', array( $this, 'purge_post' ), 10, 2 );
		add_action( 'post_updated', array( $this, 'purge_changed' ), 10, 3 );
		add_action( 'wp_trash_post', array( $this, 'purge_post_id' ) );
		add_action( 'delete_post', array( $this, 'purge_post_id' ) );
		add_action( 'edited_term', array( $this, 'purge_term' ), 10, 3 );
		foreach ( array( 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'wp_update_nav_menu', 'customize_save_after' ) as $hook ) {
			add_action( $hook, array( $this, 'purge_all' ) );
		}
	}

	/** @return string|null File to serve. */
	public function decide( array $req ): ?string {
		if ( empty( $this->settings['serve_enabled'] ) || ! $this->verified || 'GET' !== $req['method'] || '' !== $req['query'] || $req['skip'] ) {
			return null;
		}
		if ( '' !== $req['crawl'] ) {
			$run = $this->store->state( 'run' );
			if ( is_array( $run ) && '' !== (string) ( $run['crawl_key'] ?? '' ) && hash_equals( (string) $run['crawl_key'], (string) $req['crawl'] ) ) {
				return null;
			}
		}
		if ( empty( $this->settings['serve_logged_in'] ) ) {
			foreach ( $req['cookies'] as $name ) {
				if ( str_starts_with( (string) $name, 'wordpress_logged_in_' ) ) {
					return null;
				}
			}
		}
		$url = $this->paths->normalize( $req['path'] );
		if ( '' === $url || ! $this->paths->is_page( $url ) || $this->paths->is_excluded( $url, preg_split( '/\r\n|\r|\n/', (string) $this->settings['serve_exclude_urls'] ) ) ) {
			return null;
		}
		$current = $this->store->state( 'current' );
		$dir     = is_array( $current ) ? (string) ( $current['dir'] ?? '' ) : '';
		if ( ! preg_match( '/^gen-[a-f0-9]{32}$/', $dir ) ) {
			return null;
		}
		try {
			$file = EMCP_Tools_Static_Site_Paths::confine( $this->base . '/' . $dir . '/serve', $this->paths->page_path( $url ) );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
		return is_file( $file ) ? $file : null;
	}

	public function maybe_serve(): void {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against generated files only.
		$parts = explode( '?', $uri, 2 );
		$file  = $this->decide(
			array(
				'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'path'    => $parts[0],
				'query'   => $parts[1] ?? '',
				'cookies' => array_keys( $_COOKIE ),
				'crawl'   => isset( $_SERVER['HTTP_X_EMCP_STATIC_CRAWL'] ) ? (string) $_SERVER['HTTP_X_EMCP_STATIC_CRAWL'] : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'skip'    => is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_404() || is_search(),
			)
		);
		if ( null === $file ) {
			return;
		}
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=0, must-revalidate' );
		header( 'X-EMCP-Static: 1' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Journal first, then a locking read of the pointer, then delete (spec 4.4):
	 * every interleaving with a promotion either blocks it or reaches the new tree.
	 *
	 * @param string[] $urls Absolute URLs, or '*' for everything.
	 */
	public function purge( array $urls ): void {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		if ( ! $urls ) {
			return;
		}
		// With no generation and no run there is nothing to invalidate, and a run
		// started later fetches after this change; journalling would only grow.
		if ( null === $this->store->state( 'run' ) && '' === (string) ( ( (array) $this->store->state( 'current' ) )['dir'] ?? '' ) ) {
			return;
		}
		foreach ( $urls as $url ) {
			$this->store->journal_add( $url );
		}
		$current = $this->store->current_locked();
		$dir     = is_array( $current ) ? (string) ( $current['dir'] ?? '' ) : '';
		if ( '' === $dir ) {
			return;
		}
		$serve = $this->base . '/' . $dir . '/serve';
		foreach ( $urls as $url ) {
			if ( '*' === $url ) {
				EMCP_Tools_Static_Site_Files::remove_tree( $serve );
				return;
			}
			try {
				@unlink( EMCP_Tools_Static_Site_Paths::confine( $serve, $this->paths->page_path( $this->paths->normalize( $url ) ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} catch ( InvalidArgumentException $e ) {
				unset( $e );
			}
		}
	}

	private function post_urls( WP_Post $post ): array {
		$urls = array( (string) get_permalink( $post ), home_url( '/' ) );
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			foreach ( (array) get_the_terms( $post, $taxonomy ) as $term ) {
				if ( $term instanceof WP_Term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = (string) $link;
					}
				}
			}
		}
		return $urls;
	}

	public function purge_post( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->purge( $this->post_urls( $post ) );
	}

	public function purge_changed( $post_id, $after, $before ): void {
		if ( $after instanceof WP_Post && $before instanceof WP_Post && ( $after->post_password !== $before->post_password || $after->post_status !== $before->post_status ) ) {
			$this->purge( array_merge( $this->post_urls( $after ), $this->post_urls( $before ) ) );
		}
	}

	public function purge_post_id( $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			$this->purge( $this->post_urls( $post ) );
		}
	}

	public function purge_term( $term_id, $tt_id, $taxonomy ): void {
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			$this->purge( array( (string) $link, home_url( '/' ) ) );
		}
	}

	public function purge_all(): void {
		$this->purge( array( '*' ) );
	}
}
