<?php
/**
 * Link rewriting for exported files (spec 2, 4.3). Targets come from the run's
 * manifest; a same-origin URL outside it keeps its absolute live URL.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Rewriter {

	const ASSET_EXT = 'js|css|woff2?|ttf|eot|otf|png|jpe?g|gif|webp|avif|svg|ico|mp4|webm|mp3|pdf';

	/** @var EMCP_Tools_Static_Site_Paths */
	private $paths;
	/** @var string */
	private $site_url;
	/** @var callable */
	private $target_for;
	/** @var string relative|offline|absolute */
	private $style;
	/** @var string */
	private $base_url;

	public function __construct( EMCP_Tools_Static_Site_Paths $paths, string $site_url, callable $target_for, string $style, string $base_url = '' ) {
		$this->paths      = $paths;
		$this->site_url   = rtrim( $site_url, '/' );
		$this->target_for = $target_for;
		$this->style      = in_array( $style, array( 'relative', 'offline', 'absolute' ), true ) ? $style : 'relative';
		$this->base_url   = rtrim( $base_url, '/' );
	}

	public function html( string $html, string $page_url, string $file_path ): string {
		$html = (string) preg_replace_callback(
			'#\b(href|src|action|poster|data-src)=(["\'])(.*?)\2#is',
			fn( $m ) => $m[1] . '=' . $m[2] . $this->rewrite( $m[3], $page_url, $file_path ) . $m[2],
			$html
		);
		$html = (string) preg_replace_callback(
			'#\b(srcset|data-srcset)=(["\'])(.*?)\2#is',
			fn( $m ) => $m[1] . '=' . $m[2] . $this->srcset( $m[3], $page_url, $file_path ) . $m[2],
			$html
		);
		$html = $this->css_urls( $html, $page_url, $file_path );
		$site = preg_quote( $this->site_url, '#' );
		return (string) preg_replace_callback(
			'#(["\'])(' . $site . '/[^\s"\'<>]+?\.(?:' . self::ASSET_EXT . '))\1#i',
			fn( $m ) => $m[1] . $this->rewrite( $m[2], $page_url, $file_path ) . $m[1],
			$html
		);
	}

	public function css( string $css, string $css_url, string $file_path ): string {
		$css = $this->css_urls( $css, $css_url, $file_path );
		return (string) preg_replace_callback(
			'#@import\s+(["\'])([^"\']+)\1#i',
			fn( $m ) => '@import ' . $m[1] . $this->rewrite( $m[2], $css_url, $file_path ) . $m[1],
			$css
		);
	}

	private function css_urls( string $text, string $from_url, string $file_path ): string {
		return (string) preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+?)\1\s*\)#i',
			fn( $m ) => 0 === stripos( trim( $m[2] ), 'data:' ) ? $m[0] : 'url(' . $m[1] . $this->rewrite( trim( $m[2] ), $from_url, $file_path ) . $m[1] . ')',
			$text
		);
	}

	private function srcset( string $value, string $from_url, string $file_path ): string {
		$parts = array();
		foreach ( explode( ',', $value ) as $part ) {
			$pieces = preg_split( '/(\s+)/', trim( $part ), 2, PREG_SPLIT_DELIM_CAPTURE );
			$parts[] = $this->rewrite( (string) $pieces[0], $from_url, $file_path ) . ( isset( $pieces[2] ) ? $pieces[1] . $pieces[2] : '' );
		}
		return implode( ', ', $parts );
	}

	public function rewrite( string $raw, string $from_url, string $file_path ): string {
		$url = $this->paths->normalize( $raw, $from_url );
		if ( '' === $url || ! $this->paths->is_same_origin( $url ) ) {
			return $raw;
		}
		$hash     = strpos( $raw, '#' );
		$fragment = false === $hash ? '' : substr( $raw, $hash );
		$target   = ( $this->target_for )( $url );
		if ( null === $target ) {
			return self::safe( $url ) . $fragment;
		}
		return $this->link( $target, $file_path ) . $fragment;
	}

	/**
	 * normalize() decodes HTML entities, so a decoded URL is percent-encoded
	 * where it could end an attribute, a CSS url() or a script string, or open
	 * a tag. Without this, `href="/x?a=&quot;onmouseover=..."` would come back
	 * as a bare quote and add an attribute the original page never had.
	 */
	private static function safe( string $url ): string {
		return strtr( $url, array( '"' => '%22', "'" => '%27', '<' => '%3C', '>' => '%3E', '`' => '%60', ' ' => '%20', '\\' => '%5C', '(' => '%28', ')' => '%29' ) );
	}

	private function link( string $target, string $file_path ): string {
		if ( 'absolute' === $this->style ) {
			return $this->base_url . '/' . ( 'index.html' === $target ? '' : self::pretty( $target ) );
		}
		$rel = self::relative( dirname( $file_path ), $target );
		return 'offline' === $this->style ? $rel : self::pretty( $rel );
	}

	/** about/index.html -> about/, index.html -> ./ */
	private static function pretty( string $path ): string {
		if ( 'index.html' === $path ) {
			return './';
		}
		return str_ends_with( $path, '/index.html' ) ? substr( $path, 0, -10 ) : $path;
	}

	public static function relative( string $from_dir, string $target ): string {
		$from   = ( '.' === $from_dir || '' === $from_dir ) ? array() : explode( '/', $from_dir );
		$to     = explode( '/', $target );
		$common = 0;
		while ( $common < count( $from ) && $common < count( $to ) - 1 && $from[ $common ] === $to[ $common ] ) {
			$common++;
		}
		$rel = str_repeat( '../', count( $from ) - $common ) . implode( '/', array_slice( $to, $common ) );
		return '' === $rel ? './' : $rel;
	}
}
