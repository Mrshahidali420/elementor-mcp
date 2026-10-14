<?php
/**
 * History for the taxonomy term tools: records create-term, update-term and
 * delete-term, and undoes them through the change log's add-on rollback filters
 * (rollback type `term`).
 *
 * Undo rules: a create is undone by deleting the term, an update by putting the
 * fields and the touched meta keys back, a delete by restoring the term under its
 * original ids with its meta, its posts, its children under it again, and the
 * default term WordPress gave orphaned posts taken off. Links are checked before
 * anything is written: a parent that is gone is `linked_unavailable`, a slug
 * another term took is `slug_taken`, and a term linked from a menu is never
 * deleted by an undo (WordPress would delete the menu items too).
 *
 * @package EMCP_Tools
 * @since   3.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Term History.
 *
 * @since 3.20.0
 */
final class EMCP_Tools_Term_History {

	const TYPE   = 'term';
	const DOMAIN = 'terms';

	/** Hook the rollback filters. */
	public static function boot(): void {
		add_filter( 'emcp_tools_change_current_hash', array( __CLASS__, 'current_hash' ), 10, 2 );
		add_filter( 'emcp_tools_change_apply_rollback', array( __CLASS__, 'apply_rollback' ), 10, 3 );
	}

	/**
	 * @param int $id Created term id.
	 * @return string Change id ('' when not recorded).
	 */
	public static function record_create( int $id ): string {
		$term = EMCP_Tools_Term_Api::get()->term( $id );
		if ( ! $term ) {
			return '';
		}
		return self::record(
			'create-term',
			$term,
			/* translators: 1: term name, 2: taxonomy name. */
			sprintf( __( 'Created term "%1$s" in %2$s', 'emcp-tools' ), $term['name'], $term['taxonomy'] ),
			array( 'mode' => 'create', 'term_id' => $id, 'taxonomy' => $term['taxonomy'], 'scope' => 'create', 'after_hash' => self::hash( $id, 'create' ) )
		);
	}

	/**
	 * @param int   $id          Term id.
	 * @param array $before      Fields before the write.
	 * @param array $before_meta Touched meta keys => values before ([] when the key was unset).
	 * @return string
	 */
	public static function record_update( int $id, array $before, array $before_meta ): string {
		$keys = array_keys( $before_meta );
		sort( $keys );
		return self::record(
			'update-term',
			$before,
			/* translators: 1: term name, 2: taxonomy name. */
			sprintf( __( 'Updated term "%1$s" in %2$s', 'emcp-tools' ), $before['name'], $before['taxonomy'] ),
			array( 'mode' => 'update', 'term_id' => $id, 'taxonomy' => $before['taxonomy'], 'scope' => 'update', 'meta_keys' => $keys, 'before' => $before, 'before_meta' => $before_meta, 'after_hash' => self::hash( $id, 'update', $keys ) )
		);
	}

	/**
	 * Everything a delete-term undo needs, taken before the delete.
	 *
	 * @param int $id Term id.
	 * @return array
	 */
	public static function snapshot( int $id ): array {
		$api  = EMCP_Tools_Term_Api::get();
		$term = (array) $api->term( $id );
		$tax  = (string) $term['taxonomy'];
		$only = array();
		$objs = $api->objects( $id, $tax );
		foreach ( $objs as $object ) {
			if ( array( $id ) === $api->object_terms( $object, $tax ) ) {
				$only[] = $object;
			}
		}
		return array(
			'term'         => $term + array( 'term_taxonomy_id' => $api->term_taxonomy_id( $id, $tax ) ),
			'meta'         => $api->meta( $id ),
			'objects'      => $objs,
			'only_term_of' => $only,
			'children'     => $api->children( $id, $tax ),
			'default_term' => $api->default_term( $tax ),
		);
	}

	/**
	 * @param array $snapshot snapshot() taken before the delete.
	 * @return string
	 */
	public static function record_delete( array $snapshot ): string {
		$term = $snapshot['term'];
		$rb   = array( 'mode' => 'delete', 'term_id' => (int) $term['term_id'], 'taxonomy' => (string) $term['taxonomy'], 'scope' => 'create', 'after_hash' => 'absent' );
		if ( class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
			$rb = EMCP_Tools_Change_Recorder::attach_before( $rb, array( 'snapshot' => $snapshot ) );
		} else {
			$rb['snapshot'] = $snapshot;
		}
		return self::record(
			'delete-term',
			$term,
			/* translators: 1: term name, 2: taxonomy name. */
			sprintf( __( 'Deleted term "%1$s" from %2$s', 'emcp-tools' ), $term['name'], $term['taxonomy'] ),
			$rb
		);
	}

	/**
	 * @param string $action  Ledger action.
	 * @param array  $term    Term fields (target label).
	 * @param string $summary Summary.
	 * @param array  $rb      Rollback ref without its type.
	 */
	private static function record( string $action, array $term, string $summary, array $rb ): string {
		if ( ! class_exists( 'EMCP_Tools_Change_Log' ) || EMCP_Tools_Change_Log::$suppress ) {
			return '';
		}
		return EMCP_Tools_Change_Log::record(
			array(
				'domain'   => self::DOMAIN,
				'action'   => $action,
				'target'   => $term['taxonomy'] . ': ' . $term['name'] . ' (#' . (int) $term['term_id'] . ')',
				'summary'  => $summary,
				'rollback' => array( 'type' => self::TYPE ) + $rb,
			)
		);
	}

	/**
	 * State hash of a term: its fields and, for the update scope, the touched meta
	 * keys; for the create scope all meta, its posts and its children too. 'absent'
	 * when the term does not exist.
	 *
	 * @param int      $id    Term id.
	 * @param string   $scope update|create.
	 * @param string[] $keys  Meta keys for the update scope.
	 */
	public static function hash( int $id, string $scope, array $keys = array() ): string {
		$api  = EMCP_Tools_Term_Api::get();
		$term = $api->term( $id );
		if ( ! $term ) {
			return 'absent';
		}
		$meta  = $api->meta( $id );
		$state = array( 'fields' => array_intersect_key( $term, array_flip( array( 'taxonomy', 'name', 'slug', 'description', 'parent' ) ) ) );
		if ( 'update' === $scope ) {
			$state['meta'] = array();
			foreach ( $keys as $key ) {
				$state['meta'][ $key ] = $meta[ $key ] ?? array();
			}
		} else {
			$state['meta']     = $meta;
			$state['objects']  = $api->objects( $id, $term['taxonomy'] );
			$state['children'] = $api->children( $id, $term['taxonomy'] );
		}
		return md5( (string) wp_json_encode( $state ) );
	}

	/**
	 * emcp_tools_change_current_hash.
	 *
	 * @param string $hash Hash so far.
	 * @param array  $rb   Rollback ref.
	 */
	public static function current_hash( $hash, $rb ) {
		if ( ! is_array( $rb ) || self::TYPE !== ( $rb['type'] ?? '' ) ) {
			return $hash;
		}
		return self::hash( (int) ( $rb['term_id'] ?? 0 ), (string) ( $rb['scope'] ?? 'create' ), (array) ( $rb['meta_keys'] ?? array() ) );
	}

	/**
	 * emcp_tools_change_apply_rollback.
	 *
	 * @param true|WP_Error|null $result Result so far.
	 * @param array              $rb     Rollback ref (heavy part already merged).
	 * @param bool               $force  Force flag.
	 * @return true|WP_Error|null
	 */
	public static function apply_rollback( $result, $rb, $force = false ) {
		if ( null !== $result || ! is_array( $rb ) || self::TYPE !== ( $rb['type'] ?? '' ) ) {
			return $result;
		}
		$allowed = self::authorize( $rb );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		switch ( $rb['mode'] ?? '' ) {
			case 'create':
				return self::undo_create( (int) $rb['term_id'], (string) $rb['taxonomy'] );
			case 'update':
				return self::undo_update( (int) $rb['term_id'], (array) ( $rb['before'] ?? array() ), (array) ( $rb['before_meta'] ?? array() ) );
			case 'delete':
				return self::undo_delete( (array) ( $rb['snapshot'] ?? array() ) );
		}
		return new WP_Error( 'unknown_rollback', __( 'Unknown term change.', 'emcp-tools' ) );
	}

	/**
	 * The person undoing needs the same rights the undo uses: deleting the term
	 * for a create, editing it for an update, creating and assigning terms for a
	 * delete.
	 *
	 * @param array $rb Rollback ref.
	 * @return true|WP_Error
	 */
	private static function authorize( array $rb ) {
		$api  = EMCP_Tools_Term_Api::get();
		$tax  = $api->taxonomy( (string) ( $rb['taxonomy'] ?? '' ) );
		$id   = (int) ( $rb['term_id'] ?? 0 );
		$mode = (string) ( $rb['mode'] ?? '' );
		if ( ! $tax ) {
			return true; // Each undo answers linked_unavailable for a taxonomy that is gone.
		}
		$caps = $tax['caps'];
		if ( 'create' === $mode ) {
			$ok = $api->can( $caps['delete_terms'] ) && ( ! $api->term( $id ) || $api->can( 'delete_term', $id ) );
		} elseif ( 'update' === $mode ) {
			$ok = $api->can( $caps['edit_terms'] ) && ( ! $api->term( $id ) || $api->can( 'edit_term', $id ) );
		} else {
			$ok = $api->can( $caps['edit_terms'] ) && $api->can( $caps['assign_terms'] );
		}
		return $ok ? true : new WP_Error( 'cannot_rollback_target', __( 'You do not have permission to change terms in this taxonomy.', 'emcp-tools' ) );
	}

	/**
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy.
	 * @return true|WP_Error
	 */
	private static function undo_create( int $id, string $tax ) {
		$api = EMCP_Tools_Term_Api::get();
		if ( ! $api->term( $id ) ) {
			return true;
		}
		if ( $api->menus_linking( $id, $tax ) ) {
			return new WP_Error( 'term_in_menu', __( 'A menu links to this term; deleting it would delete those menu items too. Remove them from the menu first.', 'emcp-tools' ) );
		}
		if ( $api->default_term( $tax ) === $id ) {
			return new WP_Error( 'default_term', __( 'This term is now the default term of its taxonomy and cannot be deleted.', 'emcp-tools' ) );
		}
		return $api->delete( $id, $tax );
	}

	/**
	 * @param int   $id          Term id.
	 * @param array $before      Fields before.
	 * @param array $before_meta Meta key => values before.
	 * @return true|WP_Error
	 */
	private static function undo_update( int $id, array $before, array $before_meta ) {
		$api  = EMCP_Tools_Term_Api::get();
		$term = $api->term( $id );
		if ( ! $term ) {
			return new WP_Error( 'linked_unavailable', __( 'The term no longer exists.', 'emcp-tools' ) );
		}
		$tax  = $term['taxonomy'];
		$link = self::check_links( $before, $id );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		$res = $api->update( $id, $tax, array_intersect_key( $before, array_flip( array( 'name', 'slug', 'description', 'parent' ) ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		foreach ( $before_meta as $key => $values ) {
			if ( array() === $values ) {
				$api->delete_meta( $id, (string) $key );
			} else {
				$api->set_meta( $id, (string) $key, (array) $values );
			}
		}
		return true;
	}

	/**
	 * @param array $snap snapshot().
	 * @return true|WP_Error
	 */
	private static function undo_delete( array $snap ) {
		$api  = EMCP_Tools_Term_Api::get();
		$term = (array) ( $snap['term'] ?? array() );
		if ( empty( $term['term_id'] ) || empty( $term['taxonomy'] ) ) {
			return new WP_Error( 'blob_missing', __( 'The saved snapshot for this change is no longer available.', 'emcp-tools' ) );
		}
		$tax = (string) $term['taxonomy'];
		if ( ! $api->taxonomy( $tax ) ) {
			return new WP_Error( 'linked_unavailable', __( 'The taxonomy is no longer registered.', 'emcp-tools' ) );
		}
		if ( $api->term( (int) $term['term_id'] ) ) {
			return new WP_Error( 'conflict', __( 'A term with this id exists again; it was not restored.', 'emcp-tools' ) );
		}
		$link = self::check_links( $term, 0 );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		$id = $api->restore( $term );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( (array) ( $snap['meta'] ?? array() ) as $key => $values ) {
			$api->set_meta( (int) $id, (string) $key, (array) $values );
		}
		$default = (int) ( $snap['default_term'] ?? 0 );
		$only    = array_map( 'intval', (array) ( $snap['only_term_of'] ?? array() ) );
		foreach ( (array) ( $snap['objects'] ?? array() ) as $object ) {
			$object = (int) $object;
			if ( ! $api->object_exists( $object, $tax ) ) {
				continue;
			}
			// WordPress gave posts left with no term the default term; take it off
			// again only where it is still the only term they have.
			$gave_default = $default && $default !== (int) $id && in_array( $object, $only, true ) && array( $default ) === $api->object_terms( $object, $tax );
			$api->add_object_term( $object, (int) $id, $tax );
			if ( $gave_default ) {
				$api->remove_object_term( $object, $default, $tax );
			}
		}
		// WordPress moved the children up a level; put back the ones still there.
		foreach ( (array) ( $snap['children'] ?? array() ) as $child ) {
			$c = $api->term( (int) $child );
			if ( $c && $c['taxonomy'] === $tax && (int) $c['parent'] === (int) $term['parent'] ) {
				$api->update( (int) $child, $tax, array( 'parent' => (int) $id ) );
			}
		}
		return true;
	}

	/**
	 * The parent a restore puts back must still be a term of the same taxonomy, and
	 * the slug must not belong to another term.
	 *
	 * @param array $fields Fields to restore.
	 * @param int   $self   The term being restored (0 for a deleted one).
	 * @return true|WP_Error
	 */
	private static function check_links( array $fields, int $self ) {
		$api    = EMCP_Tools_Term_Api::get();
		$parent = (int) ( $fields['parent'] ?? 0 );
		if ( $parent > 0 ) {
			$p = $api->term( $parent );
			if ( ! $p || $p['taxonomy'] !== $fields['taxonomy'] ) {
				return new WP_Error( 'linked_unavailable', __( 'The parent term it had is gone; nothing was restored.', 'emcp-tools' ) );
			}
		}
		if ( isset( $fields['slug'] ) ) {
			$other = $api->find_slug( (string) $fields['taxonomy'], (string) $fields['slug'] );
			if ( $other && $other !== $self ) {
				return new WP_Error( 'slug_taken', __( 'Another term now uses this slug; nothing was restored.', 'emcp-tools' ) );
			}
		}
		return true;
	}
}
