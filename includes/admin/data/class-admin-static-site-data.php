<?php
/**
 * Static Site screen payload. Boot uses cached data only; the screen asks for
 * fresh Cloud data after it mounts.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Admin_Static_Site_Data {

	public static function payload( bool $fresh = false ): array {
		$connected = class_exists( 'EMCP_Tools_Cloud' ) && EMCP_Tools_Cloud::is_connected();
		$allowance = null;
		$error     = '';
		if ( $connected ) {
			$res = $fresh ? EMCP_Tools_Static_Site_Credits::allowance( true ) : get_transient( EMCP_Tools_Static_Site_Credits::ALLOWANCE_CACHE );
			if ( is_wp_error( $res ) ) {
				$error = EMCP_Tools_Static_Site_Credits::classify( $res )['code'];
			} elseif ( is_array( $res ) ) {
				$allowance = $res;
			}
		}
		$store       = new EMCP_Tools_Static_Site_Store();
		$state       = (array) $store->state( 'current' );
		$current_dir = (string) ( $state['dir'] ?? '' );
		$current     = $state;
		unset( $current['_raw'], $current['dir'], $current['local'] );
		return array(
			'settings'       => EMCP_Tools_Static_Site_Module::settings(),
			'localEnv'       => in_array( wp_get_environment_type(), array( 'local', 'development' ), true ),
			'connected'      => $connected,
			'account'        => $connected ? EMCP_Tools_Cloud::redact_email( EMCP_Tools_Cloud::account_email() ) : '',
			'allowance'      => $allowance,
			'allowanceError' => $error,
			'run'            => EMCP_Tools_Static_Site_Runner::make()->view(),
			'current'        => empty( $current['generation'] ) ? null : $current + array( 'folder' => 'local' === ( $current['delivery'] ?? '' ) ? 'wp-content/emcp-static/' . $current_dir . '/local/' . EMCP_Tools_Static_Site_Module::settings()['local_folder'] : '' ),
			'lastCancel'     => get_option( 'emcp_tools_static_last_cancel', null ),
			'denyVerified'   => EMCP_Tools_Static_Site_Module::deny_verified(),
			'nginxRule'      => 'location ^~ /wp-content/emcp-static/ { deny all; }',
			'servedCount'    => self::served_count( $current_dir ),
			'connectionUrl'  => admin_url( 'admin.php?page=emcp-tools-connection' ),
		);
	}

	private static function served_count( string $dir ): int {
		$serve = EMCP_Tools_Static_Site_Module::base_dir() . '/' . $dir . '/serve';
		if ( '' === $dir || ! is_dir( $serve ) ) {
			return 0;
		}
		$n = 0;
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $serve, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && 'html' === strtolower( $file->getExtension() ) && ++$n >= 5000 ) {
				break;
			}
		}
		return $n;
	}
}
