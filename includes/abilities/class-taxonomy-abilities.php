<?php
/**
 * WordPress taxonomy term abilities: list-terms, get-term, create-term,
 * update-term and delete-term, for categories, tags and every custom taxonomy.
 *
 * Built on WordPress core through EMCP_Tools_Term_Api. Each call checks the
 * taxonomy's own capabilities (manage/edit/delete/assign_terms, and the
 * edit_term/delete_term meta capabilities, which refuse a default term). Writes
 * are recorded in History and undone by EMCP_Tools_Term_History.
 *
 * @package EMCP_Tools
 * @since   3.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the taxonomy term abilities.
 *
 * @since 3.20.0
 */
class EMCP_Tools_Taxonomy_Abilities {

	/** @var string[] */
	private $ability_names = array();

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/** Register the five tools. */
	public function register(): void {
		$term_props = array(
			'term_id'     => array( 'type' => 'integer' ),
			'taxonomy'    => array( 'type' => 'string' ),
			'name'        => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'parent'      => array( 'type' => 'integer' ),
			'count'       => array( 'type' => 'integer' ),
			'link'        => array( 'type' => 'string' ),
		);
		$meta_input = array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'description'          => __( 'Term meta as { key: value }. A list sets several values; null removes the key. Keys starting with an underscore are protected and refused.', 'emcp-tools' ),
		);

		$this->add(
			'list-terms',
			__( 'List Terms', 'emcp-tools' ),
			__( 'Lists the terms of a taxonomy (category, post_tag or any custom taxonomy; see list-taxonomies), with search, a parent filter (0 = top level), hide_empty, ordering and paging. Returns each term with its id, slug, parent, post count and archive link.', 'emcp-tools' ),
			'execute_list_terms',
			array(
				'taxonomy'   => array( 'type' => 'string', 'description' => __( 'Taxonomy name, e.g. category, post_tag, product_cat.', 'emcp-tools' ) ),
				'search'     => array( 'type' => 'string', 'description' => __( 'Match in the name or slug.', 'emcp-tools' ) ),
				'parent'     => array( 'type' => 'integer', 'description' => __( 'Only direct children of this term; 0 for top-level terms. Omit for all.', 'emcp-tools' ) ),
				'hide_empty' => array( 'type' => 'boolean', 'description' => __( 'Skip terms with no posts. Default false.', 'emcp-tools' ) ),
				'orderby'    => array( 'type' => 'string', 'enum' => array( 'name', 'slug', 'count', 'term_id' ) ),
				'order'      => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
				'per_page'   => array( 'type' => 'integer', 'description' => __( '1 to 100, default 50.', 'emcp-tools' ) ),
				'page'       => array( 'type' => 'integer', 'description' => __( 'Default 1.', 'emcp-tools' ) ),
			),
			array( 'taxonomy' ),
			array(
				'taxonomy' => array( 'type' => 'object' ),
				'terms'    => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => $term_props ) ),
				'total'    => array( 'type' => 'integer' ),
				'page'     => array( 'type' => 'integer' ),
				'pages'    => array( 'type' => 'integer' ),
			),
			array( 'readonly' => true, 'destructive' => false, 'idempotent' => true )
		);

		$this->add(
			'get-term',
			__( 'Get Term', 'emcp-tools' ),
			__( 'Returns one term by id: name, slug, description, parent, post count, archive link, its term meta and its direct children.', 'emcp-tools' ),
			'execute_get_term',
			array( 'term_id' => array( 'type' => 'integer', 'description' => __( 'The term id.', 'emcp-tools' ) ) ),
			array( 'term_id' ),
			$term_props + array( 'meta' => array( 'type' => 'object' ), 'children' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ) ),
			array( 'readonly' => true, 'destructive' => false, 'idempotent' => true )
		);

		$this->add(
			'create-term',
			__( 'Create Term', 'emcp-tools' ),
			__( 'Creates a term in any taxonomy (a category, a tag, a product category, a custom taxonomy term) with an optional slug, description, parent (hierarchical taxonomies only) and term meta. Undo it from History.', 'emcp-tools' ),
			'execute_create_term',
			array(
				'taxonomy'    => array( 'type' => 'string', 'description' => __( 'Taxonomy name.', 'emcp-tools' ) ),
				'name'        => array( 'type' => 'string', 'description' => __( 'Term name.', 'emcp-tools' ) ),
				'slug'        => array( 'type' => 'string', 'description' => __( 'URL slug; made from the name when omitted.', 'emcp-tools' ) ),
				'description' => array( 'type' => 'string' ),
				'parent'      => array( 'type' => 'integer', 'description' => __( 'Parent term id in the same taxonomy (hierarchical taxonomies only).', 'emcp-tools' ) ),
				'meta'        => $meta_input,
			),
			array( 'taxonomy', 'name' ),
			array( 'term' => array( 'type' => 'object', 'properties' => $term_props ), 'change_id' => array( 'type' => 'string' ) ),
			array( 'readonly' => false, 'destructive' => false, 'idempotent' => false )
		);

		$this->add(
			'update-term',
			__( 'Update Term', 'emcp-tools' ),
			__( 'Updates a term by id. Only the fields you send change: name, slug, description, parent (0 moves it to the top level) and term meta. Undo it from History.', 'emcp-tools' ),
			'execute_update_term',
			array(
				'term_id'     => array( 'type' => 'integer', 'description' => __( 'The term id.', 'emcp-tools' ) ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'parent'      => array( 'type' => 'integer' ),
				'meta'        => $meta_input,
			),
			array( 'term_id' ),
			array( 'term' => array( 'type' => 'object', 'properties' => $term_props ), 'change_id' => array( 'type' => 'string' ) ),
			array( 'readonly' => false, 'destructive' => false, 'idempotent' => true )
		);

		$this->add(
			'delete-term',
			__( 'Delete Term', 'emcp-tools' ),
			__( 'Permanently deletes a term (terms have no trash). Requires confirm:true. WordPress moves its child terms up a level and gives posts left with no term the default term. The default term, and a term a menu links to, are refused. Undo it from History: the term comes back with its id, meta, posts and children.', 'emcp-tools' ),
			'execute_delete_term',
			array(
				'term_id' => array( 'type' => 'integer', 'description' => __( 'The term id.', 'emcp-tools' ) ),
				'confirm' => array( 'type' => 'boolean', 'description' => __( 'Must be true.', 'emcp-tools' ) ),
			),
			array( 'term_id', 'confirm' ),
			array(
				'deleted'        => array( 'type' => 'boolean' ),
				'term_id'        => array( 'type' => 'integer' ),
				'children_moved' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'objects'        => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'objects_count'  => array( 'type' => 'integer' ),
				'change_id'      => array( 'type' => 'string' ),
			),
			array( 'readonly' => false, 'destructive' => true, 'idempotent' => false )
		);
	}

	/**
	 * Register one ability.
	 *
	 * @param string $slug        Name after emcp-tools/.
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param string $method      Execute method.
	 * @param array  $props       Input properties.
	 * @param array  $required    Required inputs.
	 * @param array  $output      Output properties.
	 * @param array  $annotations MCP annotations.
	 */
	private function add( string $slug, string $label, string $description, string $method, array $props, array $required, array $output, array $annotations ): void {
		$name                  = 'emcp-tools/' . $slug;
		$this->ability_names[] = $name;
		emcp_tools_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, $method ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => $props, 'required' => $required ),
				'output_schema'       => array( 'type' => 'object', 'properties' => $output ),
				'meta'                => array( 'annotations' => $annotations, 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Tool-level gate; each call then checks the taxonomy's own capabilities.
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' ) || current_user_can( 'manage_categories' );
	}

	/**
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute_list_terms( $input ) {
		$tax = $this->readable_taxonomy( (string) ( $input['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$orderby  = in_array( $input['orderby'] ?? '', array( 'name', 'slug', 'count', 'term_id' ), true ) ? $input['orderby'] : 'name';
		$res      = EMCP_Tools_Term_Api::get()->terms(
			array(
				'taxonomy'   => $tax['name'],
				'search'     => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				'parent'     => isset( $input['parent'] ) ? (int) $input['parent'] : null,
				'hide_empty' => ! empty( $input['hide_empty'] ),
				'orderby'    => $orderby,
				'order'      => 'desc' === strtolower( (string) ( $input['order'] ?? '' ) ) ? 'DESC' : 'ASC',
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
			)
		);
		return array(
			'taxonomy' => array_intersect_key( $tax, array_flip( array( 'name', 'label', 'hierarchical', 'public', 'object_type' ) ) ),
			'terms'    => array_map( array( $this, 'shape' ), $res['items'] ),
			'total'    => (int) $res['total'],
			'page'     => $page,
			'pages'    => (int) ceil( $res['total'] / $per_page ),
		);
	}

	/**
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute_get_term( $input ) {
		$api  = EMCP_Tools_Term_Api::get();
		$term = $api->term( (int) ( $input['term_id'] ?? 0 ) );
		if ( ! $term ) {
			return new WP_Error( 'term_not_found', __( 'Term not found.', 'emcp-tools' ), array( 'status' => 404 ) );
		}
		$tax = $this->readable_taxonomy( $term['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$meta = array();
		foreach ( $api->meta( $term['term_id'] ) as $key => $values ) {
			// Protected keys (leading underscore) hold plugins' internal data.
			if ( $api->is_protected_meta( (string) $key ) ) {
				continue;
			}
			$meta[ $key ] = 1 === count( $values ) ? $values[0] : $values;
		}
		return $this->shape( $term ) + array(
			'meta'     => $meta ? $meta : (object) array(),
			'children' => $api->children( $term['term_id'], $term['taxonomy'] ),
		);
	}

	/**
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute_create_term( $input ) {
		$api = EMCP_Tools_Term_Api::get();
		$tax = $api->taxonomy( sanitize_key( (string) ( $input['taxonomy'] ?? '' ) ) );
		if ( ! $tax ) {
			return $this->no_taxonomy();
		}
		if ( empty( $tax['show_ui'] ) ) {
			return $this->not_editable();
		}
		if ( ! $api->can( $tax['caps']['edit_terms'] ) ) {
			return $this->forbidden();
		}
		$name = trim( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'missing_params', __( 'name is required.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		$parent = (int) ( $input['parent'] ?? 0 );
		$check  = $this->check_parent( $tax, $parent, 0 );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$meta = $this->meta_input( $input );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$id = $api->insert(
			$tax['name'],
			array(
				'name'        => $name,
				'slug'        => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
				'description' => (string) ( $input['description'] ?? '' ),
				'parent'      => $parent,
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( $meta as $key => $values ) {
			if ( null !== $values ) {
				$api->set_meta( $id, $key, $values );
			}
		}
		$change = EMCP_Tools_Term_History::record_create( $id );
		return array( 'term' => $this->shape( (array) $api->term( $id ) ), 'change_id' => $change );
	}

	/**
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute_update_term( $input ) {
		$api  = EMCP_Tools_Term_Api::get();
		$id   = (int) ( $input['term_id'] ?? 0 );
		$term = $api->term( $id );
		if ( ! $term ) {
			return new WP_Error( 'term_not_found', __( 'Term not found.', 'emcp-tools' ), array( 'status' => 404 ) );
		}
		$tax = $api->taxonomy( $term['taxonomy'] );
		if ( ! $tax ) {
			return $this->no_taxonomy();
		}
		if ( empty( $tax['show_ui'] ) ) {
			return $this->not_editable();
		}
		if ( ! $api->can( 'edit_term', $id ) || ! $api->can( $tax['caps']['edit_terms'] ) ) {
			return $this->forbidden();
		}
		$fields = array();
		foreach ( array( 'name', 'description' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$fields[ $k ] = (string) $input[ $k ];
			}
		}
		if ( isset( $fields['name'] ) && '' === trim( $fields['name'] ) ) {
			return new WP_Error( 'missing_params', __( 'name cannot be empty.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		if ( isset( $input['slug'] ) ) {
			$fields['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['parent'] ) ) {
			$fields['parent'] = (int) $input['parent'];
			$check            = $this->check_parent( $tax, $fields['parent'], $id );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		$meta = $this->meta_input( $input );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		if ( ! $fields && ! $meta ) {
			return new WP_Error( 'missing_params', __( 'Send at least one of name, slug, description, parent or meta.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		$before_meta = array();
		$current     = $api->meta( $id );
		foreach ( array_keys( $meta ) as $key ) {
			$before_meta[ $key ] = $current[ $key ] ?? array();
		}
		if ( $fields ) {
			$res = $api->update( $id, $tax['name'], $fields );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		foreach ( $meta as $key => $values ) {
			if ( null === $values ) {
				$api->delete_meta( $id, $key );
			} else {
				$api->set_meta( $id, $key, $values );
			}
		}
		$change = EMCP_Tools_Term_History::record_update( $id, $term, $before_meta );
		return array( 'term' => $this->shape( (array) $api->term( $id ) ), 'change_id' => $change );
	}

	/**
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute_delete_term( $input ) {
		if ( empty( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new WP_Error( 'confirm_required', __( 'Deleting a term is permanent. Pass confirm:true to proceed.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		$api  = EMCP_Tools_Term_Api::get();
		$id   = (int) ( $input['term_id'] ?? 0 );
		$term = $api->term( $id );
		if ( ! $term ) {
			return new WP_Error( 'term_not_found', __( 'Term not found.', 'emcp-tools' ), array( 'status' => 404 ) );
		}
		$tax = $api->taxonomy( $term['taxonomy'] );
		if ( $tax && empty( $tax['show_ui'] ) ) {
			return $this->not_editable();
		}
		// delete_term refuses the taxonomy's default term (core maps it to do_not_allow).
		if ( ! $tax || ! $api->can( 'delete_term', $id ) || ! $api->can( $tax['caps']['delete_terms'] ) ) {
			return $this->forbidden();
		}
		$menus = $api->menus_linking( $id, $term['taxonomy'] );
		if ( $menus ) {
			return new WP_Error(
				'term_in_menu',
				sprintf(
					/* translators: %s: menu names. */
					__( 'A menu links to this term (%s). WordPress would delete those menu items with it; remove them from the menu first.', 'emcp-tools' ),
					implode( ', ', array_unique( array_filter( array_column( $menus, 'menu' ) ) ) )
				),
				array( 'status' => 409, 'menu_items' => $menus )
			);
		}
		$snapshot = EMCP_Tools_Term_History::snapshot( $id );
		$res      = $api->delete( $id, $term['taxonomy'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$change = EMCP_Tools_Term_History::record_delete( $snapshot );
		return array(
			'deleted'        => true,
			'term_id'        => $id,
			'children_moved' => $snapshot['children'],
			'objects'        => array_slice( $snapshot['objects'], 0, 100 ),
			'objects_count'  => count( $snapshot['objects'] ),
			'change_id'      => $change,
		);
	}

	/**
	 * A registered taxonomy the user may read terms of.
	 *
	 * @param string $name Taxonomy.
	 * @return array|WP_Error
	 */
	private function readable_taxonomy( string $name ) {
		$api = EMCP_Tools_Term_Api::get();
		$tax = $api->taxonomy( sanitize_key( $name ) );
		if ( ! $tax ) {
			return $this->no_taxonomy();
		}
		// A public taxonomy's terms are readable by anyone who may assign them; a
		// non-public one (internal or plugin data) needs manage_terms.
		$can = $api->can( $tax['caps']['manage_terms'] ) || ( ! empty( $tax['public'] ) && $api->can( $tax['caps']['assign_terms'] ) );
		return $can ? $tax : $this->forbidden();
	}

	/**
	 * A parent must be 0, or a term of the same hierarchical taxonomy that is not
	 * the term itself or one of its descendants.
	 *
	 * @param array $tax    Taxonomy.
	 * @param int   $parent Parent id.
	 * @param int   $self   Term being updated (0 on create).
	 * @return true|WP_Error
	 */
	private function check_parent( array $tax, int $parent, int $self ) {
		if ( 0 === $parent ) {
			return true;
		}
		if ( ! $tax['hierarchical'] ) {
			return new WP_Error( 'not_hierarchical', __( 'This taxonomy is not hierarchical, so its terms have no parent.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		$api  = EMCP_Tools_Term_Api::get();
		$seen = array();
		for ( $at = $parent; $at > 0; ) {
			$t = $api->term( $at );
			if ( ! $t || $t['taxonomy'] !== $tax['name'] || $at === $self || isset( $seen[ $at ] ) ) {
				return new WP_Error( 'invalid_parent', __( 'The parent must be another term of the same taxonomy, and not the term itself or one of its children.', 'emcp-tools' ), array( 'status' => 400 ) );
			}
			$seen[ $at ] = true;
			$at          = (int) $t['parent'];
		}
		return true;
	}

	/**
	 * The meta input as key => list of values (null removes the key).
	 *
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	private function meta_input( $input ) {
		$raw = $input['meta'] ?? array();
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'invalid_meta', __( 'meta must be an object of key => value.', 'emcp-tools' ), array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $raw as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || strlen( $key ) > 255 ) {
				return new WP_Error( 'invalid_meta', __( 'Meta keys must be 1 to 255 characters.', 'emcp-tools' ), array( 'status' => 400 ) );
			}
			if ( EMCP_Tools_Term_Api::get()->is_protected_meta( $key ) ) {
				/* translators: %s: meta key. */
				return new WP_Error( 'protected_meta', sprintf( __( 'The meta key %s is protected.', 'emcp-tools' ), $key ), array( 'status' => 400 ) );
			}
			if ( null === $value ) {
				$out[ $key ] = null;
			} elseif ( is_array( $value ) && array_is_list( $value ) ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = array( $value );
			}
		}
		return $out;
	}

	/**
	 * The term as the tools answer it.
	 *
	 * @param array $term Term fields.
	 */
	private function shape( array $term ): array {
		return array(
			'term_id'     => (int) $term['term_id'],
			'taxonomy'    => (string) $term['taxonomy'],
			'name'        => (string) $term['name'],
			'slug'        => (string) $term['slug'],
			'description' => (string) $term['description'],
			'parent'      => (int) $term['parent'],
			'count'       => (int) ( $term['count'] ?? 0 ),
			'link'        => EMCP_Tools_Term_Api::get()->link( (int) $term['term_id'] ),
		);
	}

	private function no_taxonomy(): WP_Error {
		return new WP_Error( 'invalid_taxonomy', __( 'Unknown taxonomy. list-taxonomies shows the registered ones.', 'emcp-tools' ), array( 'status' => 404 ) );
	}

	private function not_editable(): WP_Error {
		return new WP_Error( 'taxonomy_not_editable', __( 'This taxonomy is internal (it has no admin screen), so its terms are not edited with these tools.', 'emcp-tools' ), array( 'status' => 403 ) );
	}

	private function forbidden(): WP_Error {
		return new WP_Error( 'forbidden', __( 'You are not allowed to do this with this taxonomy or term.', 'emcp-tools' ), array( 'status' => 403 ) );
	}
}
