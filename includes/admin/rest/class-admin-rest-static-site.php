<?php
/**
 * Static Site screen REST (cookie-only through the base controller).
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Admin_REST_Static_Site extends EMCP_Tools_Admin_REST_Controller {

	public function register_routes(): void {
		$this->route( 'static-site/settings', array( 'methods' => 'POST', 'callback' => array( $this, 'save_settings' ) ) );
		$this->route( 'static-site/pages', array( 'methods' => 'GET', 'callback' => array( $this, 'pages' ) ) );
		$this->route( 'static-site/build/step', array( 'methods' => 'POST', 'callback' => array( $this, 'step' ) ) );
		$this->route( 'static-site/build/continue', array( 'methods' => 'POST', 'callback' => array( $this, 'takeover' ) ) );
		$this->route( 'static-site/build/resolve', array( 'methods' => 'POST', 'callback' => array( $this, 'resolve' ) ) );
		$this->route( 'static-site/build/cancel', array( 'methods' => 'POST', 'callback' => array( $this, 'cancel' ) ) );
		$this->route( 'static-site/build', array( 'methods' => 'POST', 'callback' => array( $this, 'build' ) ) );
		$this->route( 'static-site/serve/check', array( 'methods' => 'POST', 'callback' => array( $this, 'serve_check' ) ) );
		$this->route( 'static-site/serve/purge', array( 'methods' => 'POST', 'callback' => array( $this, 'purge' ) ) );
		$this->route( 'static-site/download/(?P<generation>[a-f0-9-]{36})', array( 'methods' => 'GET', 'callback' => array( $this, 'download' ) ) );
		$this->route( 'static-site', array( 'methods' => 'GET', 'callback' => array( $this, 'get' ) ) );
	}

	public static function register_screen(): void {
		EMCP_Tools_Admin_Screens::register(
			'static-site',
			array(
				'script' => 'screen-static-site',
				'tabs'   => array( 'static-site' ),
				'boot'   => static fn(): array => EMCP_Tools_Admin_Static_Site_Data::payload(),
			)
		);
	}

	private static function owner( $request ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $request->get_param( 'owner' ) ), 0, 64 );
	}

	private static function gen( $request ): string {
		$g = (string) $request->get_param( 'generation' );
		return preg_match( '/^[a-f0-9-]{36}$/', $g ) ? $g : '';
	}

	public function get( $request ) {
		return rest_ensure_response( EMCP_Tools_Admin_Static_Site_Data::payload( (bool) $request->get_param( 'fresh' ) ) );
	}

	public function save_settings( $request ) {
		$in       = (array) $request->get_param( 'settings' );
		$settings = EMCP_Tools_Static_Site_Module::sanitize( $in );
		if ( $settings['serve_enabled'] && ! EMCP_Tools_Static_Site_Module::deny_verified() ) {
			return new WP_Error( 'deny_check_required', __( 'Run the server check before serving static pages.', 'emcp-tools' ), array( 'status' => 409 ) );
		}
		EMCP_Tools_Static_Site_Module::save_settings( $settings );
		return rest_ensure_response( EMCP_Tools_Admin_Static_Site_Data::payload() );
	}

	public function pages( $request ) {
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( strlen( $q ) < 2 ) {
			return rest_ensure_response( array( 'results' => array() ) );
		}
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$query = new WP_Query( array( 's' => $q, 'post_type' => array_keys( $types ), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 20, 'fields' => 'ids', 'no_found_rows' => true ) );
		$out   = array();
		foreach ( $query->posts as $id ) {
			$post  = get_post( $id );
			$out[] = array( 'id' => (int) $id, 'title' => EMCP_Tools_Static_Site_Module::plain_title( get_the_title( $id ) ), 'url' => (string) get_permalink( $id ), 'type' => isset( $types[ $post->post_type ] ) ? $types[ $post->post_type ]->labels->singular_name : $post->post_type );
		}
		return rest_ensure_response( array( 'results' => $out ) );
	}

	public function build( $request ) {
		$mode = 'pages' === $request->get_param( 'mode' ) ? 'pages' : 'full';
		$urls = array_slice( array_map( 'esc_url_raw', (array) $request->get_param( 'urls' ) ), 0, 100 );
		if ( 'pages' === $mode && ! $urls ) {
			return new WP_Error( 'no_pages', __( 'Choose at least one page.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		$owner = self::owner( $request );
		if ( '' === $owner ) {
			return new WP_Error( 'owner_required', __( 'Reload the page and try again.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( EMCP_Tools_Static_Site_Runner::make()->start( $mode, $urls, $owner ) );
	}

	public function step( $request ) {
		return rest_ensure_response( EMCP_Tools_Static_Site_Runner::make()->step( self::gen( $request ), self::owner( $request ), (int) $request->get_param( 'epoch' ) ) );
	}

	public function takeover( $request ) {
		return rest_ensure_response( EMCP_Tools_Static_Site_Runner::make()->takeover( self::gen( $request ), self::owner( $request ) ) );
	}

	public function resolve( $request ) {
		return rest_ensure_response( EMCP_Tools_Static_Site_Runner::make()->resolve( self::gen( $request ) ) );
	}

	public function cancel( $request ) {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'confirm_required', __( 'Confirm the cancellation.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( EMCP_Tools_Static_Site_Runner::make()->cancel( self::gen( $request ), self::owner( $request ), (int) $request->get_param( 'epoch' ) ) );
	}

	public function serve_check( $request ) {
		$settings = EMCP_Tools_Static_Site_Module::settings();
		EMCP_Tools_Static_Site_Files::protect_base( EMCP_Tools_Static_Site_Module::base_dir() );
		$result = EMCP_Tools_Static_Site_Deny_Check::run( EMCP_Tools_Static_Site_Module::base_dir(), EMCP_Tools_Static_Site_Module::base_url(), EMCP_Tools_Static_Site_Module::verify_tls( $settings ) );
		EMCP_Tools_Static_Site_Module::save_deny( $result );
		return rest_ensure_response( $result );
	}

	public function purge( $request ) {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'confirm_required', __( 'Confirm the purge.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		( new EMCP_Tools_Static_Site_Server( new EMCP_Tools_Static_Site_Store(), new EMCP_Tools_Static_Site_Paths( home_url( '/' ) ), EMCP_Tools_Static_Site_Module::base_dir(), EMCP_Tools_Static_Site_Module::settings(), false ) )->purge_all();
		return rest_ensure_response( EMCP_Tools_Admin_Static_Site_Data::payload() );
	}

	public function download( $request ) {
		$gen     = self::route_param( $request, 'generation' );
		$current = (array) ( new EMCP_Tools_Static_Site_Store() )->state( 'current' );
		if ( ( $current['generation'] ?? '' ) !== $gen || empty( $current['zip'] ) || ! preg_match( '/^gen-[a-f0-9]{32}$/', (string) ( $current['dir'] ?? '' ) ) ) {
			return new WP_Error( 'not_found', __( 'This ZIP is no longer available.', 'emcp-tools' ), array( 'status' => 404 ) );
		}
		$file = EMCP_Tools_Static_Site_Module::base_dir() . '/' . $current['dir'] . '/zips/' . basename( (string) $current['zip'] );
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'not_found', __( 'This ZIP is no longer available.', 'emcp-tools' ), array( 'status' => 404 ) );
		}
		$host = (string) preg_replace( '/[^a-z0-9.-]/i', '-', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $host . '-static-' . gmdate( 'Y-m-d', strtotime( (string) $current['date'] ) ) . '.zip"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
