<?php
/**
 * Widget Bundle Adapter — presents the existing Widget Builder store
 * (`EMCP_Tools_Widget_Store`) as a portable, cloud-ready
 * `EMCP_Tools_Sandbox_Artifact`, without changing the store's internals.
 *
 * @package EMCP_Tools
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin adapter: reads/writes go through `EMCP_Tools_Widget_Store`'s existing
 * static API; this class only shapes the result into (and out of) the
 * `EMCP_Tools_Sandbox_Bundle` envelope.
 *
 * @since 3.7.0
 */
class EMCP_Tools_Widget_Bundle_Adapter implements EMCP_Tools_Sandbox_Artifact {

	const META_UUID = '_emcp_uuid';

	/**
	 * @since 3.7.0
	 * @return string
	 */
	public function kind(): string {
		return 'widget';
	}

	/**
	 * Mints (once) and returns the shared cross-kind UUID for a widget post.
	 *
	 * @since 3.7.0
	 *
	 * @param int $id Widget post ID.
	 * @return string
	 */
	public function uuid( int $id ): string {
		$u = (string) get_post_meta( $id, self::META_UUID, true );
		if ( '' === $u ) {
			$u = wp_generate_uuid4();
			update_post_meta( $id, self::META_UUID, $u );
		}
		return $u;
	}

	/**
	 * @since 3.7.0
	 *
	 * @param int $id Widget post ID.
	 * @return array
	 */
	public function sync_meta( int $id ): array {
		return array(
			'uuid'       => $this->uuid( $id ),
			'origin'     => (string) get_post_meta( $id, '_emcp_origin', true ) ?: 'local',
			'remote_id'  => (string) get_post_meta( $id, '_emcp_remote_id', true ),
			'sync_state' => (string) get_post_meta( $id, '_emcp_sync_state', true ) ?: 'dirty',
			'version'    => (int) get_post_meta( $id, '_emcp_version', true ),
			'updated_at' => (string) get_post_meta( $id, '_emcp_updated_at', true ),
		);
	}

	/**
	 * @since 3.7.0
	 *
	 * @param int $id Widget post ID.
	 * @return string
	 */
	public function checksum( int $id ): string {
		return EMCP_Tools_Sandbox_Bundle::checksum( $this->assets( $id ) );
	}

	/**
	 * The widget's generated files (PHP always present; CSS/JS only if set).
	 *
	 * @param int $id Widget post ID.
	 * @return array<string,string>
	 */
	private function assets( int $id ): array {
		$assets = array( 'widget.php' => EMCP_Tools_Widget_Store::get_php( $id ) );
		$css    = EMCP_Tools_Widget_Store::get_css( $id );
		if ( '' !== $css ) {
			$assets['style.css'] = $css;
		}
		$js = EMCP_Tools_Widget_Store::get_js( $id );
		if ( '' !== $js ) {
			$assets['script.js'] = $js;
		}
		return $assets;
	}

	/**
	 * @since 3.7.0
	 *
	 * @param int $id Widget post ID.
	 * @return array|WP_Error
	 */
	public function to_bundle( int $id ) {
		$summary = EMCP_Tools_Widget_Store::summary( $id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		$spec = EMCP_Tools_Widget_Store::get_spec( $id ) ?? array();
		$sm   = $this->sync_meta( $id );
		return EMCP_Tools_Sandbox_Bundle::build(
			'widget',
			$sm['uuid'],
			array(
				'title'       => (string) ( $summary['title'] ?? '' ),
				'description' => '',
				'author'      => (string) ( wp_get_current_user()->user_login ?? '' ),
				'license'     => 'GPL-2.0-or-later',
			),
			$spec,
			$this->assets( $id ),
			max( 1, $sm['version'] ),
			$sm['updated_at'] ?: gmdate( 'c' )
		);
	}

	/**
	 * Imports a bundle as a NEW draft widget (never overwrites an existing one).
	 *
	 * @since 3.7.0
	 *
	 * @param array $bundle Bundle as produced by to_bundle()/validate()-shaped.
	 * @return int|WP_Error New local widget post ID.
	 */
	public function apply_bundle( array $bundle ) {
		// Imported widgets carry front-end code: only users trusted with unfiltered HTML may add them.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new WP_Error( 'emcp_sandbox_unfiltered_html', __( 'Importing custom widgets and blocks requires the unfiltered_html capability.', 'emcp-tools' ) );
		}
		$valid = EMCP_Tools_Sandbox_Bundle::validate( $bundle );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 'widget' !== $bundle['kind'] ) {
			return new WP_Error( 'kind_mismatch', __( 'Bundle is not a widget.', 'emcp-tools' ) );
		}
		$res = EMCP_Tools_Widget_Store::create( is_array( $bundle['spec'] ) ? $bundle['spec'] : array(), false );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$new_id = (int) $res['widget_id'];
		update_post_meta( $new_id, self::META_UUID, sanitize_text_field( (string) $bundle['uuid'] ) );
		update_post_meta( $new_id, '_emcp_origin', 'imported' );
		return $new_id;
	}
}
