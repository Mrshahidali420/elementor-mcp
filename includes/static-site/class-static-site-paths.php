<?php
/**
 * URL normalisation and URL-to-file mapping for the Static Site module (spec 2, 4.3).
 * Pure PHP: no WordPress calls, so it unit-tests without a site.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Paths {

	const PAGE_EXT   = array( 'html', 'htm' );
	const ASSET_DIRS = array(
		'css'    => array( 'css' ),
		'js'     => array( 'js', 'map' ),
		'images' => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp' ),
		'fonts'  => array( 'woff', 'woff2', 'ttf', 'eot', 'otf' ),
		'media'  => array( 'mp4', 'webm', 'mp3', 'ogg', 'wav' ),
		'files'  => array( 'pdf', 'json', 'xml', 'txt' ),
	);

	/** @var string */
	private $scheme;
	/** @var string */
	private $host;
	/** @var int */
	private $port;
	/** @var string */
	private $origin;

	public function __construct( string $site_url ) {
		$p            = parse_url( $site_url );
		$this->scheme = strtolower( (string) ( $p['scheme'] ?? 'https' ) );
		$this->host   = strtolower( (string) ( $p['host'] ?? '' ) );
		$this->port   = isset( $p['port'] ) ? (int) $p['port'] : self::default_port( $this->scheme );
		$this->origin = $this->scheme . '://' . $this->host . ( self::default_port( $this->scheme ) === $this->port ? '' : ':' . $this->port );
	}

	public function origin(): string {
		return $this->origin;
	}

	private static function default_port( string $scheme ): int {
		return 'http' === $scheme ? 80 : 443;
	}

	/**
	 * Absolute http(s) URL without fragment, query kept, dot segments resolved.
	 *
	 * @param string $url  Raw value from an attribute, CSS or a setting.
	 * @param string $base URL the value was found in (for relative values).
	 * @return string '' when the value is not a fetchable URL.
	 */
	public function normalize( string $url, string $base = '' ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url || '#' === $url[0] || preg_match( '#^(data|mailto|tel|sms|javascript|about|blob):#i', $url ) ) {
			return '';
		}
		$url = (string) preg_replace( '/#.*$/s', '', $url );
		if ( str_starts_with( $url, '//' ) ) {
			$url = $this->scheme . ':' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$url = $this->origin . $url;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			$base_path = (string) ( parse_url( '' !== $base ? $base : $this->origin . '/', PHP_URL_PATH ) ?? '/' );
			$dir       = (string) preg_replace( '#[^/]*$#', '', '' === $base_path ? '/' : $base_path );
			$url       = $this->origin . $dir . $url;
		}
		$p = parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( $p['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$port  = isset( $p['port'] ) && (int) $p['port'] !== self::default_port( $scheme ) ? ':' . (int) $p['port'] : '';
		$query = isset( $p['query'] ) && '' !== $p['query'] ? '?' . $p['query'] : '';
		return $scheme . '://' . strtolower( $p['host'] ) . $port . self::dot_segments( (string) ( $p['path'] ?? '/' ) ) . $query;
	}

	public static function dot_segments( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}
		$out = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $out );
				continue;
			}
			if ( '.' === $seg ) {
				continue;
			}
			$out[] = $seg;
		}
		$joined = '/' . ltrim( implode( '/', $out ), '/' );
		if ( ( str_ends_with( $path, '/' ) || preg_match( '#/\.\.?$#', $path ) ) && ! str_ends_with( $joined, '/' ) ) {
			$joined .= '/';
		}
		return $joined;
	}

	public function is_same_origin( string $url ): bool {
		$p = parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
		$port   = isset( $p['port'] ) ? (int) $p['port'] : self::default_port( $scheme );
		return $scheme === $this->scheme && strtolower( $p['host'] ) === $this->host && $port === $this->port;
	}

	/**
	 * @param string   $url      Normalised URL.
	 * @param string[] $patterns One per line; `/path`, full URLs, `*` wildcards.
	 */
	public function is_excluded( string $url, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( str_starts_with( $pattern, '/' ) ) {
				$pattern = $this->origin . $pattern;
			}
			if ( str_contains( $pattern, '*' ) ) {
				if ( preg_match( '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i', $url ) ) {
					return true;
				}
			} elseif ( rtrim( $url, '/' ) === rtrim( $pattern, '/' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function extension( string $url ): string {
		return strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
	}

	public function asset_category( string $url ): ?string {
		$ext = self::extension( $url );
		foreach ( self::ASSET_DIRS as $category => $extensions ) {
			if ( in_array( $ext, $extensions, true ) ) {
				return $category;
			}
		}
		return null;
	}

	public function is_page( string $url ): bool {
		return null === $this->asset_category( $url );
	}

	public function has_query( string $url ): bool {
		return '' !== (string) parse_url( $url, PHP_URL_QUERY );
	}

	/** Output path of a page: directory style, never an executable name. */
	public function page_path( string $url ): string {
		$path = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return 'index.html';
		}
		$segs = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg ) {
				continue;
			}
			$seg    = ltrim( (string) preg_replace( '/[^\p{L}\p{N}._~-]+/u', '-', rawurldecode( $seg ) ), '.' );
			$segs[] = '' === $seg ? '-' : $seg;
		}
		$ext = strtolower( pathinfo( (string) end( $segs ), PATHINFO_EXTENSION ) );
		return in_array( $ext, self::PAGE_EXT, true ) ? implode( '/', $segs ) : implode( '/', $segs ) . '/index.html';
	}

	public function output_path( string $url, EMCP_Tools_Static_Site_Registry $registry ): string {
		$category = $this->asset_category( $url );
		return null === $category ? $this->page_path( $url ) : $registry->path_for( $url, $category, self::asset_name( $url ) );
	}

	/** File name of an asset; a query other than a bare `ver=` adds 8 hex of its hash. */
	public static function asset_name( string $url ): string {
		$base = ltrim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', rawurldecode( basename( (string) parse_url( $url, PHP_URL_PATH ) ) ) ), '.' );
		if ( '' === $base ) {
			$base = 'file';
		}
		$query = (string) parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query || preg_match( '/^ver=[^&]*$/', $query ) ) {
			return $base;
		}
		$ext  = pathinfo( $base, PATHINFO_EXTENSION );
		$stem = pathinfo( $base, PATHINFO_FILENAME );
		return $stem . '-' . substr( hash( 'sha256', $query ), 0, 8 ) . ( '' !== $ext ? '.' . $ext : '' );
	}

	public static function url_hash( string $url ): string {
		return hash( 'sha256', $url );
	}

	/**
	 * Full path of $relative inside $base, or an exception. Checks segments, then
	 * the real path of the deepest existing ancestor, so a symlinked folder cannot
	 * lead outside the base.
	 */
	public static function confine( string $base, string $relative ): string {
		if ( '' === $relative || str_contains( $relative, "\0" ) || str_contains( $relative, '\\' ) || str_starts_with( $relative, '/' ) || preg_match( '#^[A-Za-z]:#', $relative ) ) {
			throw new InvalidArgumentException( 'unsafe_path' );
		}
		foreach ( explode( '/', $relative ) as $seg ) {
			if ( '' === $seg || '.' === $seg || '..' === $seg ) {
				throw new InvalidArgumentException( 'unsafe_path' );
			}
		}
		$base   = rtrim( str_replace( '\\', '/', $base ), '/' );
		$full   = $base . '/' . $relative;
		$parent = dirname( $full );
		while ( ! file_exists( $parent ) && strlen( $parent ) > strlen( $base ) ) {
			$parent = dirname( $parent );
		}
		$real_base   = realpath( $base );
		$real_parent = realpath( $parent );
		if ( false === $real_base || false === $real_parent ) {
			throw new InvalidArgumentException( 'unsafe_path' );
		}
		$rb = rtrim( str_replace( '\\', '/', $real_base ), '/' );
		$rp = rtrim( str_replace( '\\', '/', $real_parent ), '/' );
		if ( $rp !== $rb && ! str_starts_with( $rp . '/', $rb . '/' ) ) {
			throw new InvalidArgumentException( 'unsafe_path' );
		}
		return $full;
	}
}
