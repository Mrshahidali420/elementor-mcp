<?php
/**
 * URL sources for a full-site generation (ported from 5DP), paged by a cursor so
 * Discover resumes across steps (spec 4.3).
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Discovery {

	const PAGE    = 200;
	const SOURCES = array( 'home', 'posts', 'terms', 'archives', 'authors', 'post_type_archives', 'additional' );

	/** @return array{urls: string[], cursor: ?array} */
	public static function collect( array $cursor, array $settings ): array {
		$source  = in_array( $cursor['source'] ?? '', self::SOURCES, true ) ? $cursor['source'] : 'home';
		$offset  = max( 0, (int) ( $cursor['offset'] ?? 0 ) );
		$include = (array) ( $settings['include'] ?? array() );
		$urls    = array();
		$more    = false;
		switch ( $source ) {
			case 'home':
				$urls[] = home_url( '/' );
				$blog   = (int) get_option( 'page_for_posts' );
				if ( $blog ) {
					$urls[] = (string) get_permalink( $blog );
				}
				$urls[] = home_url( '/wp-sitemap.xml' );
				break;
			case 'posts':
				$types = array();
				if ( in_array( 'posts', $include, true ) ) {
					$types[] = 'post';
				}
				if ( in_array( 'pages', $include, true ) ) {
					$types[] = 'page';
				}
				if ( in_array( 'cpts', $include, true ) ) {
					$types = array_merge( $types, array_values( get_post_types( array( 'public' => true, '_builtin' => false ), 'names' ) ) );
				}
				if ( $types ) {
					$ids = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => self::PAGE, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => false ) );
					foreach ( $ids as $id ) {
						$urls[] = (string) get_permalink( $id );
					}
					$more = count( $ids ) === self::PAGE;
				}
				break;
			case 'terms':
				$taxonomies = array();
				if ( in_array( 'categories', $include, true ) ) {
					$taxonomies[] = 'category';
				}
				if ( in_array( 'tags', $include, true ) ) {
					$taxonomies[] = 'post_tag';
				}
				if ( $taxonomies ) {
					$terms = get_terms( array( 'taxonomy' => $taxonomies, 'hide_empty' => true, 'number' => self::PAGE, 'offset' => $offset, 'orderby' => 'term_id' ) );
					foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
						$link = get_term_link( $term );
						if ( ! is_wp_error( $link ) ) {
							$urls[] = (string) $link;
						}
					}
					$more = ! is_wp_error( $terms ) && count( $terms ) === self::PAGE;
				}
				break;
			case 'archives':
				if ( in_array( 'archives', $include, true ) ) {
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$months = $wpdb->get_results( "SELECT DISTINCT YEAR(post_date) AS y, MONTH(post_date) AS m FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' ORDER BY y DESC, m DESC" );
					$years  = array();
					foreach ( (array) $months as $row ) {
						$urls[] = (string) get_month_link( (int) $row->y, (int) $row->m );
						if ( ! isset( $years[ $row->y ] ) ) {
							$urls[]            = (string) get_year_link( (int) $row->y );
							$years[ $row->y ] = true;
						}
					}
				}
				break;
			case 'authors':
				if ( in_array( 'authors', $include, true ) ) {
					$users = get_users( array( 'has_published_posts' => true, 'fields' => 'ID', 'number' => self::PAGE, 'offset' => $offset ) );
					foreach ( $users as $user_id ) {
						$urls[] = (string) get_author_posts_url( (int) $user_id );
					}
					$more = count( $users ) === self::PAGE;
				}
				break;
			case 'post_type_archives':
				if ( in_array( 'cpts', $include, true ) ) {
					foreach ( get_post_types( array( 'public' => true, '_builtin' => false, 'has_archive' => true ), 'names' ) as $type ) {
						$link = get_post_type_archive_link( $type );
						if ( $link ) {
							$urls[] = (string) $link;
						}
					}
				}
				break;
			case 'additional':
				foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $settings['additional_urls'] ?? '' ) ) as $line ) {
					if ( '' !== trim( $line ) ) {
						$urls[] = trim( $line );
					}
				}
				break;
		}
		if ( $more ) {
			return array( 'urls' => $urls, 'cursor' => array( 'source' => $source, 'offset' => $offset + self::PAGE ) );
		}
		$next = array_search( $source, self::SOURCES, true ) + 1;
		return array( 'urls' => $urls, 'cursor' => isset( self::SOURCES[ $next ] ) ? array( 'source' => self::SOURCES[ $next ], 'offset' => 0 ) : null );
	}
}
