<?php
/**
 * Static Site module (free, off by default). Generations are metered by EMCP Cloud.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMCP_Tools_Static_Site_Module extends EMCP_Tools_Module {

	const ID              = 'static-site';
	const OPTION_SETTINGS = 'emcp_tools_static_settings';
	const OPTION_DENY     = 'emcp_tools_static_deny_verified';
	const DB_VERSION      = 1;
	const DB_OPTION       = 'emcp_tools_static_db_version';
	const CRON            = 'emcp_tools_static_cleanup';
	const INCLUDE_TYPES   = array( 'posts', 'pages', 'archives', 'categories', 'tags', 'authors', 'cpts' );

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Static Site', 'emcp-tools' );
	}

	public function description(): string {
		return __( 'Turn this site into plain HTML files: download them as a ZIP, keep them in a folder, or serve them to visitors. Each generation is checked against your EMCP Cloud account.', 'emcp-tools' );
	}

	public function tier(): string {
		return 'free';
	}

	public function group(): string {
		return 'site';
	}

	public function icon(): string {
		return 'archive';
	}

	public function default_active(): bool {
		return false;
	}

	public function is_available(): bool {
		return '' === $this->unavailable_reason();
	}

	public function unavailable_reason(): string {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return __( 'Not available on WordPress multisite.', 'emcp-tools' );
		}
		if ( ! ( class_exists( 'EMCP_Tools_Cloud_Module' ) && EMCP_Tools_Cloud_Module::is_enabled() ) ) {
			return __( 'Switch on the EMCP Cloud module first.', 'emcp-tools' );
		}
		if ( defined( 'WP_CONTENT_DIR' ) && ! is_writable( WP_CONTENT_DIR ) && ! is_dir( self::base_dir() ) ) {
			return __( 'WordPress cannot write to wp-content.', 'emcp-tools' );
		}
		return '';
	}

	public function settings_url(): string {
		return admin_url( 'admin.php?page=emcp-tools-static-site' );
	}

	public function render_settings(): void {}

	public static function is_enabled(): bool {
		$active = (array) get_option( EMCP_Tools_Module::OPTION_ACTIVE, array() );
		return in_array( self::ID, $active, true );
	}

	public function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 20 );
		add_action( self::CRON, array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
		( new EMCP_Tools_Static_Site_Server( new EMCP_Tools_Static_Site_Store(), new EMCP_Tools_Static_Site_Paths( home_url( '/' ) ), self::base_dir(), self::settings(), self::deny_verified() ) )->register();
	}

	/**
	 * register() runs only while the module is on, so it cannot unschedule
	 * its own cron; this watches the active-modules option instead.
	 */
	public static function watch_activation(): void {
		add_action( 'update_option_' . EMCP_Tools_Module::OPTION_ACTIVE, array( __CLASS__, 'on_modules_changed' ), 10, 2 );
	}

	/**
	 * @param mixed $old Previous active module ids.
	 * @param mixed $now New active module ids.
	 */
	public static function on_modules_changed( $old, $now ): void {
		if ( ! in_array( self::ID, is_array( $now ) ? $now : array(), true ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		if ( ( new EMCP_Tools_Static_Site_Store() )->install() ) {
			update_option( self::DB_OPTION, self::DB_VERSION, true );
		}
	}

	public static function base_dir(): string {
		return rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/emcp-static';
	}

	public static function base_url(): string {
		return content_url( 'emcp-static' );
	}

	public static function defaults(): array {
		return array(
			'delivery'           => 'zip',
			'local_folder'       => 'site',
			'url_style'          => 'relative',
			'base_url'           => '',
			'include'            => self::INCLUDE_TYPES,
			'additional_urls'    => '',
			'exclude_urls'       => '',
			'batch_size'         => 50,
			'accept_self_signed' => false,
			'serve_enabled'      => false,
			'serve_logged_in'    => false,
			'serve_exclude_urls' => '',
		);
	}

	private static function lines( $value, int $max = 200 ): string {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' !== $line ) {
				$out[] = substr( $line, 0, 2000 );
			}
		}
		return implode( "\n", array_slice( $out, 0, $max ) );
	}

	/**
	 * @param array       $in  Raw settings.
	 * @param string|null $env Environment type (for tests); defaults to wp_get_environment_type().
	 */
	public static function sanitize( array $in, ?string $env = null ): array {
		$d   = self::defaults();
		$env = $env ?? ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' );
		$out = $d;

		$out['delivery']  = in_array( $in['delivery'] ?? '', array( 'zip', 'local' ), true ) ? $in['delivery'] : $d['delivery'];
		$folder           = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) ( $in['local_folder'] ?? '' ) ), '-' ) );
		$out['local_folder'] = ( '' !== $folder && strlen( $folder ) <= 40 && ! str_contains( (string) ( $in['local_folder'] ?? '' ), '.' ) ) ? $folder : $d['local_folder'];
		$out['url_style'] = in_array( $in['url_style'] ?? '', array( 'relative', 'offline', 'absolute' ), true ) ? $in['url_style'] : $d['url_style'];
		$base             = (string) ( $in['base_url'] ?? '' );
		$out['base_url']  = preg_match( '#^https?://[^\s/]+#i', $base ) ? rtrim( esc_url_raw( $base ), '/' ) : '';
		$include          = array_values( array_intersect( (array) ( $in['include'] ?? $d['include'] ), self::INCLUDE_TYPES ) );
		$out['include']   = $include;
		$out['additional_urls']    = self::lines( $in['additional_urls'] ?? '' );
		$out['exclude_urls']       = self::lines( $in['exclude_urls'] ?? '' );
		$out['serve_exclude_urls'] = self::lines( $in['serve_exclude_urls'] ?? '' );
		$out['batch_size']         = max( 10, min( 200, (int) ( $in['batch_size'] ?? $d['batch_size'] ) ) );
		$out['accept_self_signed'] = in_array( $env, array( 'local', 'development' ), true ) && ! empty( $in['accept_self_signed'] );
		$out['serve_enabled']      = ! empty( $in['serve_enabled'] );
		$out['serve_logged_in']    = ! empty( $in['serve_logged_in'] );
		if ( 'absolute' === $out['url_style'] && '' === $out['base_url'] ) {
			$out['url_style'] = 'relative';
		}
		return $out;
	}

	/** WordPress titles carry HTML entities; the screen prints text, so decode them. */
	public static function plain_title( string $title ): string {
		return html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The settings, the deny check and the schema version are read on every
	 * front-end request while the module is on, so they are autoloaded.
	 */
	public static function save_settings( array $settings ): void {
		update_option( self::OPTION_SETTINGS, $settings, true );
	}

	public static function save_deny( array $result ): void {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
		update_option( self::OPTION_DENY, $result + array( 'server' => $software, 'at' => time() ), true );
	}

	public static function settings(): array {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	public static function deny_verified(): bool {
		$v = get_option( self::OPTION_DENY, array() );
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
		return is_array( $v ) && ! empty( $v['passed'] ) && ( $v['server'] ?? '' ) === $software;
	}

	/** Daily: remove generation folders that are neither current, nor the active run, nor younger than 7 days. */
	public static function cleanup(): void {
		$store   = new EMCP_Tools_Static_Site_Store();
		$current = (array) $store->state( 'current' );
		$run     = (array) $store->state( 'run' );
		$keep    = array_filter( array( $current['dir'] ?? '', $run['dir'] ?? '' ) );
		foreach ( glob( self::base_dir() . '/gen-*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( in_array( basename( $dir ), $keep, true ) || filemtime( $dir ) > time() - 7 * DAY_IN_SECONDS ) {
				continue;
			}
			EMCP_Tools_Static_Site_Files::remove_tree( $dir );
		}
		if ( ! empty( $run['generation'] ) ) {
			$store->journal_prune( (string) $run['generation'] );
		}
	}
}
