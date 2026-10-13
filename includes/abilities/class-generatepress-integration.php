<?php
/**
 * GeneratePress theme-settings pack.
 *
 * `generatepress-read`  : get-settings ({ group?, keys? }) — curated GP settings + metadata
 * `generatepress-write` : update-settings ({ values }) — allowlisted writes, skipped[] for the rest
 *
 * Registers only when GeneratePress is the active theme (free since 3.20.0).
 * GeneratePress stores its Customizer settings in the single `generate_settings`
 * option and reads them (merged with defaults) via `generate_get_option()`. Colors
 * reference a `global_colors` palette by CSS variable (e.g. var(--accent)). After a
 * write we clear GP's cached dynamic CSS so it regenerates.
 *
 * @package EMCP_Tools
 * @since   3.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GeneratePress theme settings integration.
 */
class EMCP_Tools_GeneratePress_Integration extends EMCP_Tools_Theme_Integration {

	const OPTION = 'generate_settings';

	/**
	 * Curated allowlist: key => { type, label, group, shape }. Verified against
	 * GeneratePress 3.6.1.
	 *
	 * @var array<string,array{type:string,label:string,group:string,shape:string}>
	 */
	const ALLOWLIST = array(
		'global_colors'         => array( 'type' => 'array',  'label' => 'Global color palette', 'group' => 'palette', 'shape' => 'Array of { name, slug, color } — the palette other colors reference as var(--slug), e.g. var(--accent).' ),
		'background_color'      => array( 'type' => 'color',  'label' => 'Body background',       'group' => 'colors', 'shape' => 'CSS color or a palette var, e.g. "var(--base-2)".' ),
		'text_color'           => array( 'type' => 'color',  'label' => 'Body text color',       'group' => 'colors', 'shape' => 'CSS color or palette var, e.g. "var(--contrast)".' ),
		'link_color'           => array( 'type' => 'color',  'label' => 'Link color',            'group' => 'colors', 'shape' => 'CSS color or palette var, e.g. "var(--accent)".' ),
		'link_color_hover'     => array( 'type' => 'color',  'label' => 'Link hover color',      'group' => 'colors', 'shape' => 'CSS color or palette var.' ),
		'container_width'      => array( 'type' => 'number', 'label' => 'Container width (px)',  'group' => 'layout', 'shape' => 'Integer px, e.g. 1200.' ),
		'content_layout_setting' => array( 'type' => 'select', 'label' => 'Content layout',      'group' => 'layout', 'shape' => '"separate-containers" | "one-container" | "full-width".' ),
		'container_alignment'  => array( 'type' => 'select', 'label' => 'Container alignment',   'group' => 'layout', 'shape' => '"boxed" | "text".' ),
		'body_font_size'       => array( 'type' => 'number', 'label' => 'Body font size (px)',   'group' => 'typography', 'shape' => 'Integer px.' ),
	);

	public function id(): string {
		return 'generatepress';
	}

	public function label(): string {
		return __( 'GeneratePress', 'emcp-tools' );
	}

	public function is_available(): bool {
		return function_exists( 'get_template' ) && 'generatepress' === get_template();
	}

	protected function operations(): array {
		return array(
			'get-settings'    => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_get_settings' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'Read curated GeneratePress settings with value + type/label/group/shape metadata. Optional { group } (palette|colors|layout|typography) or { keys: [...] }. Colors reference the global palette as var(--slug).', 'emcp-tools' ),
			),
			'update-settings' => array(
				'mode' => 'write',
				'run'  => array( $this, 'execute_update_settings' ),
				'perm' => array( $this, 'can_write' ),
				'desc' => __( 'Write curated GeneratePress settings ({ values: { key: value } }). Non-allowlisted keys are reported in skipped[]. Read the shape from get-settings first (global_colors is an array of { name, slug, color }).', 'emcp-tools' ),
			),
		);
	}

	/**
	 * @param array $input { group?, keys? }.
	 * @return array
	 */
	public function execute_get_settings( $input ): array {
		$group = isset( $input['group'] ) ? (string) $input['group'] : '';
		$keys  = ( isset( $input['keys'] ) && is_array( $input['keys'] ) ) ? array_map( 'strval', $input['keys'] ) : array();

		$out = array();
		foreach ( self::ALLOWLIST as $key => $meta ) {
			if ( '' !== $group && $meta['group'] !== $group ) {
				continue;
			}
			if ( ! empty( $keys ) && ! in_array( $key, $keys, true ) ) {
				continue;
			}
			$out[ $key ] = array(
				'value' => $this->read_value( $key ),
				'type'  => $meta['type'],
				'label' => $meta['label'],
				'group' => $meta['group'],
				'shape' => $meta['shape'],
			);
		}
		return array( 'settings' => $out );
	}

	/**
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private function read_value( string $key ) {
		if ( function_exists( 'generate_get_option' ) ) {
			return generate_get_option( $key );
		}
		$opt = get_option( self::OPTION, array() );
		return is_array( $opt ) && array_key_exists( $key, $opt ) ? $opt[ $key ] : null;
	}

	/**
	 * @param array $input { values }.
	 * @return array|WP_Error
	 */
	public function execute_update_settings( $input ) {
		$values = ( isset( $input['values'] ) && is_array( $input['values'] ) ) ? $input['values'] : array();
		if ( empty( $values ) ) {
			return new WP_Error( 'missing_values', __( 'Provide a "values" object of GeneratePress setting key => value pairs.', 'emcp-tools' ), array( 'status' => 400 ) );
		}

		$option = get_option( self::OPTION, array() );
		$option = is_array( $option ) ? $option : array();

		$updated = array();
		$skipped = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( self::ALLOWLIST[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}
			$option[ $key ] = $value;
			$updated[]      = $key;
		}

		if ( ! empty( $updated ) ) {
			update_option( self::OPTION, $option );
			$this->flush_dynamic_css();
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}

	/**
	 * GeneratePress caches its generated CSS in options keyed by a version; clear
	 * them so the new settings take effect on the next request.
	 */
	private function flush_dynamic_css(): void {
		delete_option( 'generate_dynamic_css_cached_version' );
		delete_option( 'generate_dynamic_css_output' );
	}
}
