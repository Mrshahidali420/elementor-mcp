<?php
/**
 * Thin wrapper around WordPress's term functions for the taxonomy tools and their
 * History undo. Everything here is WordPress core; unit tests swap in an
 * in-memory stand-in through use_api().
 *
 * @package EMCP_Tools
 * @since   3.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress term access.
 *
 * @since 3.20.0
 */
class EMCP_Tools_Term_Api {

	/** @var EMCP_Tools_Term_Api|null */
	private static $api;

	/** The API in use. */
	public static function get(): self {
		if ( null === self::$api ) {
			self::$api = new self();
		}
		return self::$api;
	}

	/**
	 * Swap the API (tests); null puts the real one back.
	 *
	 * @param EMCP_Tools_Term_Api|null $api API.
	 */
	public static function use_api( ?self $api ): void {
		self::$api = $api;
	}

	/**
	 * A registered taxonomy, or null.
	 *
	 * @param string $tax Taxonomy name.
	 * @return array{name:string,label:string,hierarchical:bool,public:bool,show_ui:bool,object_type:string[],caps:array}|null
	 */
	public function taxonomy( string $tax ): ?array {
		$obj = get_taxonomy( $tax );
		if ( ! $obj ) {
			return null;
		}
		return array(
			'name'         => (string) $obj->name,
			'label'        => (string) $obj->label,
			'hierarchical' => (bool) $obj->hierarchical,
			'public'       => (bool) $obj->public,
			'show_ui'      => (bool) $obj->show_ui,
			'object_type'  => array_values( (array) $obj->object_type ),
			'caps'         => array(
				'manage_terms' => (string) $obj->cap->manage_terms,
				'edit_terms'   => (string) $obj->cap->edit_terms,
				'delete_terms' => (string) $obj->cap->delete_terms,
				'assign_terms' => (string) $obj->cap->assign_terms,
			),
		);
	}

	/**
	 * Whether the current user has a capability; edit_term and delete_term take the
	 * term id (core maps delete_term to do_not_allow for a default term).
	 *
	 * @param string $cap     Capability.
	 * @param int    $term_id Term id for the term meta capabilities.
	 */
	public function can( string $cap, int $term_id = 0 ): bool {
		return $term_id > 0 ? current_user_can( $cap, $term_id ) : current_user_can( $cap );
	}

	/**
	 * The taxonomy's default term id: default_category for categories, the
	 * registered default term for others, else 0.
	 *
	 * @param string $tax Taxonomy.
	 */
	public function default_term( string $tax ): int {
		return (int) get_option( 'category' === $tax ? 'default_category' : 'default_term_' . $tax, 0 );
	}

	/**
	 * A term by id, or null.
	 *
	 * @param int $id Term id.
	 * @return array|null
	 */
	public function term( int $id ): ?array {
		$t = $id > 0 ? get_term( $id ) : null;
		if ( ! $t instanceof WP_Term ) {
			return null;
		}
		return array(
			'term_id'     => (int) $t->term_id,
			'taxonomy'    => (string) $t->taxonomy,
			'name'        => (string) $t->name,
			'slug'        => (string) $t->slug,
			'description' => (string) $t->description,
			'parent'      => (int) $t->parent,
			'count'       => (int) $t->count,
		);
	}

	/**
	 * The id of the term with this slug in the taxonomy, or 0.
	 *
	 * @param string $tax  Taxonomy.
	 * @param string $slug Slug.
	 */
	public function find_slug( string $tax, string $slug ): int {
		$t = get_term_by( 'slug', $slug, $tax );
		return $t instanceof WP_Term ? (int) $t->term_id : 0;
	}

	/**
	 * A page of terms.
	 *
	 * @param array $q taxonomy, search, parent (null for any), hide_empty, orderby, order, number, offset.
	 * @return array{items: array, total: int}
	 */
	public function terms( array $q ): array {
		$args = array(
			'taxonomy'   => $q['taxonomy'],
			'hide_empty' => ! empty( $q['hide_empty'] ),
			'search'     => (string) ( $q['search'] ?? '' ),
			'orderby'    => (string) ( $q['orderby'] ?? 'name' ),
			'order'      => (string) ( $q['order'] ?? 'ASC' ),
		);
		if ( isset( $q['parent'] ) && null !== $q['parent'] ) {
			$args['parent'] = (int) $q['parent'];
		}
		$total = (int) wp_count_terms( $args );
		$terms = get_terms( $args + array( 'number' => (int) $q['number'], 'offset' => (int) $q['offset'] ) );
		$items = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$items[] = $this->term( (int) $t->term_id );
		}
		return array( 'items' => array_values( array_filter( $items ) ), 'total' => $total );
	}

	/**
	 * Direct children of a term.
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 * @return int[]
	 */
	public function children( int $id, string $tax ): array {
		$ids = get_terms( array( 'taxonomy' => $tax, 'parent' => $id, 'hide_empty' => false, 'fields' => 'ids' ) );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Create a term.
	 *
	 * @param string $tax Taxonomy.
	 * @param array  $f   name, slug, description, parent.
	 * @return int|WP_Error Term id.
	 */
	public function insert( string $tax, array $f ) {
		$res = wp_insert_term( (string) $f['name'], $tax, array( 'slug' => (string) $f['slug'], 'description' => (string) $f['description'], 'parent' => (int) $f['parent'] ) );
		return is_wp_error( $res ) ? $res : (int) $res['term_id'];
	}

	/**
	 * Update a term's fields.
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 * @param array  $f   Any of name, slug, description, parent.
	 * @return true|WP_Error
	 */
	public function update( int $id, string $tax, array $f ) {
		$res = wp_update_term( $id, $tax, array_intersect_key( $f, array_flip( array( 'name', 'slug', 'description', 'parent' ) ) ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Delete a term, with WordPress's own side effects.
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 * @return true|WP_Error
	 */
	public function delete( int $id, string $tax ) {
		$res = wp_delete_term( $id, $tax );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return true === $res ? true : new WP_Error( 'delete_failed', __( 'WordPress did not delete the term.', 'emcp-tools' ) );
	}

	/**
	 * Put a deleted term back under its original ids when both are free, so menus,
	 * page builders and settings that name it keep working; otherwise as a new term.
	 *
	 * @param array $f term_id, term_taxonomy_id, taxonomy, name, slug, description, parent.
	 * @return int|WP_Error Term id.
	 */
	public function restore( array $f ) {
		global $wpdb;
		$id    = (int) $f['term_id'];
		$tt_id = (int) ( $f['term_taxonomy_id'] ?? 0 );
		$free  = $id > 0 && $tt_id > 0
			&& ! $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d", $id ) )
			&& ! $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) );
		if ( ! $free ) {
			return $this->insert( (string) $f['taxonomy'], $f );
		}
		$ok = $wpdb->insert( $wpdb->terms, array( 'term_id' => $id, 'name' => (string) $f['name'], 'slug' => (string) $f['slug'], 'term_group' => 0 ), array( '%d', '%s', '%s', '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'restore_failed', __( 'The term could not be restored.', 'emcp-tools' ) );
		}
		$ok = $wpdb->insert( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => $tt_id, 'term_id' => $id, 'taxonomy' => (string) $f['taxonomy'], 'description' => (string) $f['description'], 'parent' => (int) $f['parent'], 'count' => 0 ), array( '%d', '%d', '%s', '%s', '%d', '%d' ) );
		if ( false === $ok ) {
			$wpdb->delete( $wpdb->terms, array( 'term_id' => $id ), array( '%d' ) );
			return new WP_Error( 'restore_failed', __( 'The term could not be restored.', 'emcp-tools' ) );
		}
		clean_term_cache( $id, (string) $f['taxonomy'] );
		return $id;
	}

	/**
	 * Every meta key of a term with its values (unserialized), keys sorted.
	 *
	 * @param int $id Term id.
	 * @return array<string, array>
	 */
	public function meta( int $id ): array {
		$all = get_term_meta( $id );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $key => $values ) {
			$out[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Replace a meta key's values.
	 *
	 * @param int    $id     Term id.
	 * @param string $key    Meta key.
	 * @param array  $values Values.
	 */
	public function set_meta( int $id, string $key, array $values ): void {
		delete_term_meta( $id, $key );
		foreach ( $values as $value ) {
			add_term_meta( $id, $key, $value );
		}
	}

	/**
	 * @param int    $id  Term id.
	 * @param string $key Meta key.
	 */
	public function delete_meta( int $id, string $key ): void {
		delete_term_meta( $id, $key );
	}

	/**
	 * @param string $key Meta key.
	 */
	public function is_protected_meta( string $key ): bool {
		return is_protected_meta( $key, 'term' );
	}

	/**
	 * Objects (posts, or users for a user taxonomy) the term is assigned to.
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 * @return int[]
	 */
	public function objects( int $id, string $tax ): array {
		$ids = get_objects_in_term( $id, $tax );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		sort( $ids );
		return $ids;
	}

	/**
	 * Term ids an object has in the taxonomy.
	 *
	 * @param int    $object Object id.
	 * @param string $tax    Taxonomy.
	 * @return int[]
	 */
	public function object_terms( int $object, string $tax ): array {
		$ids = wp_get_object_terms( $object, $tax, array( 'fields' => 'ids' ) );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		sort( $ids );
		return $ids;
	}

	/**
	 * @param int    $object Object id.
	 * @param int    $id     Term id.
	 * @param string $tax    Taxonomy.
	 */
	public function add_object_term( int $object, int $id, string $tax ): void {
		wp_set_object_terms( $object, array( $id ), $tax, true );
	}

	/**
	 * @param int    $object Object id.
	 * @param int    $id     Term id.
	 * @param string $tax    Taxonomy.
	 */
	public function remove_object_term( int $object, int $id, string $tax ): void {
		wp_remove_object_terms( $object, array( $id ), $tax );
	}

	/**
	 * Whether an object still exists: a post for post taxonomies, a user for user ones.
	 *
	 * @param int    $object Object id.
	 * @param string $tax    Taxonomy.
	 */
	public function object_exists( int $object, string $tax ): bool {
		$types = (array) ( get_taxonomy( $tax )->object_type ?? array() );
		return in_array( 'user', $types, true ) ? (bool) get_userdata( $object ) : null !== get_post( $object );
	}

	/**
	 * Menu items linking to the term (WordPress deletes them with the term).
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 * @return array<int, array{menu_item_id: int, menu: string}>
	 */
	public function menus_linking( int $id, string $tax ): array {
		$items = get_posts(
			array(
				'post_type'      => 'nav_menu_item',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed by post type, bounded.
					array( 'key' => '_menu_item_type', 'value' => 'taxonomy' ),
					array( 'key' => '_menu_item_object', 'value' => $tax ),
					array( 'key' => '_menu_item_object_id', 'value' => (string) $id ),
				),
			)
		);
		$out = array();
		foreach ( (array) $items as $item_id ) {
			$menus = wp_get_object_terms( (int) $item_id, 'nav_menu', array( 'fields' => 'names' ) );
			$out[] = array( 'menu_item_id' => (int) $item_id, 'menu' => is_array( $menus ) && $menus ? (string) $menus[0] : '' );
		}
		return $out;
	}

	/**
	 * The term's archive URL, or '' when it has none.
	 *
	 * @param int $id Term id.
	 */
	public function link( int $id ): string {
		$url = get_term_link( $id );
		return is_wp_error( $url ) ? '' : (string) $url;
	}

	/**
	 * The term_taxonomy_id of a term, for a same-id restore.
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 */
	public function term_taxonomy_id( int $id, string $tax ): int {
		$t = get_term( $id, $tax );
		return $t instanceof WP_Term ? (int) $t->term_taxonomy_id : 0;
	}
}
