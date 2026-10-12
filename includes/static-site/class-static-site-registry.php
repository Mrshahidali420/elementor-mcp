<?php
/**
 * Collision-safe asset file names (ported from 5DP's filename registry).
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Registry {

	/** @var array<string,string> path => url */
	private $by_path = array();
	/** @var array<string,string> url => path */
	private $by_url = array();

	/** @param array<string,string> $taken Paths already used in this generation (path => url). */
	public function __construct( array $taken = array() ) {
		foreach ( $taken as $path => $url ) {
			$this->by_path[ $path ] = $url;
			$this->by_url[ $url ]   = $path;
		}
	}

	public function path_for( string $url, string $category, string $name ): string {
		if ( isset( $this->by_url[ $url ] ) ) {
			return $this->by_url[ $url ];
		}
		$candidate = 'assets/' . $category . '/' . $name;
		if ( isset( $this->by_path[ $candidate ] ) ) {
			$prefix    = self::prefix( $url );
			$stem      = pathinfo( $name, PATHINFO_FILENAME );
			$ext       = pathinfo( $name, PATHINFO_EXTENSION );
			$dot       = '' !== $ext ? '.' . $ext : '';
			$candidate = 'assets/' . $category . '/' . $prefix . '-' . $stem . $dot;
			for ( $n = 2; isset( $this->by_path[ $candidate ] ); $n++ ) {
				$candidate = 'assets/' . $category . '/' . $prefix . '-' . $stem . '-' . $n . $dot;
			}
		}
		$this->by_path[ $candidate ] = $url;
		$this->by_url[ $url ]        = $candidate;
		return $candidate;
	}

	private static function prefix( string $url ): string {
		$parts = array_values( array_filter( explode( '/', (string) parse_url( $url, PHP_URL_PATH ) ), 'strlen' ) );
		foreach ( $parts as $i => $part ) {
			if ( in_array( $part, array( 'themes', 'plugins' ), true ) && isset( $parts[ $i + 1 ] ) ) {
				return self::clean( $parts[ $i + 1 ] );
			}
		}
		return count( $parts ) >= 2 ? self::clean( $parts[ count( $parts ) - 2 ] ) : 'asset';
	}

	private static function clean( string $s ): string {
		$s = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $s ), '-' );
		return '' === $s ? 'asset' : $s;
	}
}
