<?php
/**
 * Candidate URLs from fetched HTML and CSS (ported from 5DP's extractors).
 * Returns raw values; the runner normalises them against the page or CSS URL.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Extractor {

	const ASSET_EXT = 'js|css|woff2?|ttf|eot|otf|png|jpe?g|gif|webp|avif|svg|ico|mp4|webm|mp3|pdf';

	/** @return string[] */
	/** Link relations that load something the page itself needs. */
	const ASSET_RELS = array( 'stylesheet', 'icon', 'shortcut', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon', 'preload', 'modulepreload', 'prefetch', 'manifest' );

	public static function from_html( string $html, bool $assets_only, string $site_url ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$found = array();
		$prev  = libxml_use_internal_errors( true );
		$dom   = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $assets_only ) {
			foreach ( $dom->getElementsByTagName( 'a' ) as $el ) {
				$found[] = $el->getAttribute( 'href' );
			}
		}
		foreach ( $dom->getElementsByTagName( 'link' ) as $el ) {
			// A selected-pages run keeps to the chosen pages: rel=next, prev,
			// alternate or canonical point at other pages, not at their assets.
			if ( $assets_only && ! array_intersect( preg_split( '/\s+/', strtolower( trim( $el->getAttribute( 'rel' ) ) ) ), self::ASSET_RELS ) ) {
				continue;
			}
			$found[] = $el->getAttribute( 'href' );
		}
		foreach ( array( 'script', 'img', 'source' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $el ) {
				$found[] = $el->getAttribute( 'src' );
				if ( 'script' !== $tag ) {
					$found = array_merge( $found, self::srcset( $el->getAttribute( 'srcset' ) ) );
				}
			}
		}
		foreach ( $dom->getElementsByTagName( 'video' ) as $el ) {
			$found[] = $el->getAttribute( 'poster' );
		}
		foreach ( $dom->getElementsByTagName( 'style' ) as $el ) {
			$found = array_merge( $found, self::from_css( $el->textContent ) );
		}
		$xpath = new DOMXPath( $dom );
		foreach ( $xpath->query( '//*[@style]' ) as $el ) {
			$found = array_merge( $found, self::from_css( $el->getAttribute( 'style' ) ) );
		}
		$site = preg_quote( rtrim( $site_url, '/' ), '#' );
		foreach ( $dom->getElementsByTagName( 'script' ) as $el ) {
			if ( '' !== $el->getAttribute( 'src' ) || '' === $el->textContent ) {
				continue;
			}
			if ( preg_match_all( '#["\'](' . $site . '/[^\s"\'<>]+?\.(?:' . self::ASSET_EXT . '))["\']#i', $el->textContent, $m ) ) {
				$found = array_merge( $found, $m[1] );
			}
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $found ), 'strlen' ) ) );
	}

	/** @return string[] */
	public static function srcset( string $value ): array {
		$out = array();
		foreach ( explode( ',', $value ) as $part ) {
			$pieces = preg_split( '/\s+/', trim( $part ) );
			if ( ! empty( $pieces[0] ) ) {
				$out[] = $pieces[0];
			}
		}
		return $out;
	}

	/** @return string[] */
	public static function from_css( string $css ): array {
		$found = array();
		if ( preg_match_all( '/url\(\s*([\'"]?)([^\'")]+?)\1\s*\)/i', $css, $m ) ) {
			foreach ( $m[2] as $url ) {
				if ( 0 !== stripos( trim( $url ), 'data:' ) ) {
					$found[] = trim( $url );
				}
			}
		}
		if ( preg_match_all( '/@import\s+[\'"]([^\'"]+)[\'"]/i', $css, $m ) ) {
			$found = array_merge( $found, $m[1] );
		}
		return array_values( array_unique( $found ) );
	}
}
