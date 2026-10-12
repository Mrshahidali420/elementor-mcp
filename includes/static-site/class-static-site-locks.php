<?php
/**
 * Non-waiting named locks for the Static Site runner (spec 4.4). The site lock
 * guards which run exists and which generation is current; a step lock guards
 * one run's files. Order is site before step, and a request holding only a step
 * lock may never take the site lock: take() throws when asked to.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Locks {

	const TTL = 120;

	/** @var callable|null */
	private static $impl = null;
	/** @var array<string,string> name => mysql|lease|impl */
	private static $held = array();
	/** @var string */
	private static $owner = '';

	public static function use_impl( ?callable $impl ): void {
		self::$impl = $impl;
		self::$held = array();
	}

	private static function prefix(): string {
		return isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? (string) $GLOBALS['wpdb']->prefix : '';
	}

	public static function site(): string {
		return 'emcp_ss_site_' . md5( self::prefix() );
	}

	public static function step( string $gen ): string {
		return 'emcp_ss_step_' . md5( self::prefix() . $gen );
	}

	public static function held(): array {
		return array_keys( self::$held );
	}

	private static function is_site( string $name ): bool {
		return str_starts_with( $name, 'emcp_ss_site_' );
	}

	/** Take every lock in order without waiting, or none. */
	public static function take( array $names ): bool {
		$taken = array();
		foreach ( $names as $name ) {
			if ( self::is_site( $name ) ) {
				foreach ( array_keys( self::$held ) as $held ) {
					if ( ! self::is_site( $held ) ) {
						throw new LogicException( 'lock_order' );
					}
				}
			}
			$mode = self::get( $name );
			if ( '' === $mode ) {
				self::release( $taken );
				return false;
			}
			self::$held[ $name ] = $mode;
			$taken[]             = $name;
		}
		return true;
	}

	public static function release( array $names ): void {
		foreach ( array_reverse( $names ) as $name ) {
			if ( ! isset( self::$held[ $name ] ) ) {
				continue;
			}
			$mode = self::$held[ $name ];
			unset( self::$held[ $name ] );
			if ( 'impl' === $mode ) {
				( self::$impl )( 'release', $name );
			} elseif ( 'lease' === $mode ) {
				( new EMCP_Tools_Lease() )->release( 'static-lock-' . md5( $name ), self::owner() );
			} else {
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/** @return mixed The callback's result, or WP_Error busy (409). */
	public static function with( array $names, callable $fn ) {
		if ( ! self::take( $names ) ) {
			return new WP_Error( 'busy', __( 'Another request is working on this generation. Trying again shortly.', 'emcp-tools' ), array( 'status' => 409 ) );
		}
		try {
			return $fn();
		} finally {
			self::release( $names );
		}
	}

	/** @return string mysql|lease|impl, or '' when busy. */
	private static function get( string $name ): string {
		if ( null !== self::$impl ) {
			return ( self::$impl )( 'get', $name ) ? 'impl' : '';
		}
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '1' === (string) $v ) {
			return 'mysql';
		}
		if ( null === $v ) {
			// No named locks on this database: an options-row lease, never waiting.
			return ( new EMCP_Tools_Lease() )->acquire( 'static-lock-' . md5( $name ), self::owner(), self::TTL ) ? 'lease' : '';
		}
		return '';
	}

	private static function owner(): string {
		if ( '' === self::$owner ) {
			self::$owner = 'p-' . bin2hex( random_bytes( 8 ) );
		}
		return self::$owner;
	}
}
