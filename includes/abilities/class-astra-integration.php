<?php
/**
 * Astra framework pack.
 *
 * `astra-read`  : get-settings ({ group?, keys? }) — curated Astra settings + metadata
 * `astra-write` : update-settings ({ values }) — allowlisted writes, skipped[] for the rest
 *
 * Registers only when Astra is the active theme. Reads/writes the single
 * `astra-settings` option over a curated allowlist (generic get/update shape,
 * mirroring the WordPress Settings domain).
 *
 * @package EMCP_Tools
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Astra theme settings integration.
 */
class EMCP_Tools_Astra_Integration extends EMCP_Tools_Theme_Integration {

	const OPTION = 'astra-settings';

	/**
	 * Curated allowlist: key => { type, label, group }. Verified against Astra
	 * 4.13.4 on the dev site. Extend as new groups are curated.
	 *
	 * @var array<string,array{type:string,label:string,group:string}>
	 */
	const ALLOWLIST = array(
		// colors
		'theme-color'        => array( 'type' => 'color',  'label' => 'Theme (primary) color', 'group' => 'colors' ),
		'text-color'         => array( 'type' => 'color',  'label' => 'Body text color',        'group' => 'colors' ),
		'link-color'         => array( 'type' => 'color',  'label' => 'Link color',             'group' => 'colors' ),
		'link-h-color'       => array( 'type' => 'color',  'label' => 'Link hover color',       'group' => 'colors' ),
		// typography
		'body-font-family'   => array( 'type' => 'string', 'label' => 'Body font family',       'group' => 'typography' ),
		'font-size-body'     => array( 'type' => 'array',  'label' => 'Body font size',         'group' => 'typography' ),
		'font-family-h1'     => array( 'type' => 'string', 'label' => 'H1 font family',         'group' => 'typography' ),
		'font-family-h2'     => array( 'type' => 'string', 'label' => 'H2 font family',         'group' => 'typography' ),
		'font-family-h3'     => array( 'type' => 'string', 'label' => 'H3 font family',         'group' => 'typography' ),
		'font-family-h4'     => array( 'type' => 'string', 'label' => 'H4 font family',         'group' => 'typography' ),
		'font-family-h5'     => array( 'type' => 'string', 'label' => 'H5 font family',         'group' => 'typography' ),
		'font-family-h6'     => array( 'type' => 'string', 'label' => 'H6 font family',         'group' => 'typography' ),
		// layout
		'site-content-width' => array( 'type' => 'number', 'label' => 'Site content width (px)','group' => 'layout' ),
		'site-layout'        => array( 'type' => 'select', 'label' => 'Site layout',            'group' => 'layout' ),
		// header / footer
		'header-layouts'     => array( 'type' => 'select', 'label' => 'Header layout',          'group' => 'header-footer' ),
		'footer-adv'         => array( 'type' => 'select', 'label' => 'Advanced footer layout', 'group' => 'header-footer' ),
		'footer-sml-layout'  => array( 'type' => 'select', 'label' => 'Footer bar layout',      'group' => 'header-footer' ),
	);

	public function id(): string {
		return 'astra';
	}

	public function label(): string {
		return __( 'Astra', 'emcp-tools' );
	}

	public function is_available(): bool {
		return 'astra' === get_template();
	}

	protected function operations(): array {
		return array(
			'get-settings'    => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_get_settings' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'Read curated Astra settings with value + type/label/group metadata. Optional { group } (colors|typography|layout|header-footer) or { keys: [...] }; no arg returns all.', 'emcp-tools' ),
			),
			'update-settings' => array(
				'mode' => 'write',
				'run'  => array( $this, 'execute_update_settings' ),
				'perm' => array( $this, 'can_write' ),
				'desc' => __( 'Write curated Astra settings ({ values: { key: value } }). Non-allowlisted keys are reported in skipped[].', 'emcp-tools' ),
			),
		);
	}

	/**
	 * @param array $input { group?: string, keys?: string[] }.
	 * @return array
	 */
	public function execute_get_settings( $input ): array {
		$group  = isset( $input['group'] ) ? (string) $input['group'] : '';
		$keys   = ( isset( $input['keys'] ) && is_array( $input['keys'] ) ) ? array_map( 'strval', $input['keys'] ) : array();
		$option = get_option( self::OPTION, array() );
		$option = is_array( $option ) ? $option : array();

		$out = array();
		foreach ( self::ALLOWLIST as $key => $meta ) {
			if ( '' !== $group && $meta['group'] !== $group ) {
				continue;
			}
			if ( ! empty( $keys ) && ! in_array( $key, $keys, true ) ) {
				continue;
			}
			$out[ $key ] = array(
				'value' => $this->read_value( $key, $option ),
				'type'  => $meta['type'],
				'label' => $meta['label'],
				'group' => $meta['group'],
			);
		}
		return array( 'settings' => $out );
	}

	/**
	 * The effective value of a key: Astra's own resolver when available (option
	 * or theme default), else the raw option value, else null (theme default).
	 *
	 * @param string $key    Setting key.
	 * @param array  $option The astra-settings option.
	 * @return mixed
	 */
	private function read_value( string $key, array $option ) {
		if ( function_exists( 'astra_get_option' ) ) {
			return astra_get_option( $key );
		}
		return array_key_exists( $key, $option ) ? $option[ $key ] : null;
	}

	/**
	 * @param array $input { values: { key: value } }.
	 * @return array|WP_Error
	 */
	public function execute_update_settings( $input ) {
		$values = ( isset( $input['values'] ) && is_array( $input['values'] ) ) ? $input['values'] : array();
		if ( empty( $values ) ) {
			return new WP_Error( 'missing_values', __( 'Provide a "values" object of Astra setting key => value pairs.', 'emcp-tools' ), array( 'status' => 400 ) );
		}

		$option = get_option( self::OPTION, array() );
		$option = is_array( $option ) ? $option : array();

		$updated  = array();
		$skipped  = array();
		$rejected = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( self::ALLOWLIST[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}
			// Astra prints these into its dynamic CSS and this write bypasses its
			// Customizer sanitizers, so each value is checked here.
			$clean = $this->sanitize_value( $key, $value );
			if ( is_wp_error( $clean ) ) {
				$rejected[ $key ] = $clean->get_error_message();
				continue;
			}
			$option[ $key ] = $clean;
			$updated[]      = $key;
		}

		if ( ! empty( $updated ) ) {
			update_option( self::OPTION, $option, false );
			$this->refresh_astra_cache();
		}

		return array(
			'updated'  => $updated,
			'skipped'  => $skipped,
			'rejected' => $rejected,
		);
	}

	/**
	 * A value as Astra's Customizer would store it, or an error naming why it is
	 * refused.
	 *
	 * @param string $key   Allowlisted key.
	 * @param mixed  $value Raw value.
	 * @return mixed|WP_Error
	 */
	private function sanitize_value( string $key, $value ) {
		switch ( self::ALLOWLIST[ $key ]['type'] ) {
			case 'color':
				$color = self::css_color( $value );
				return null === $color ? new WP_Error( 'invalid_color', __( 'Use a hex color, rgb(), rgba(), a palette var such as var(--ast-global-color-0), or "" to reset.', 'emcp-tools' ) ) : $color;
			case 'string':
				$family = self::font_family( $value );
				return null === $family ? new WP_Error( 'invalid_font', __( 'Use a font list such as "\'Inter\', sans-serif" or inherit.', 'emcp-tools' ) ) : $family;
			case 'number':
				$number = self::whole_number( $value, 200, 5000 );
				return null === $number ? new WP_Error( 'invalid_number', __( 'Use a whole number of pixels from 200 to 5000.', 'emcp-tools' ) ) : $number;
			case 'select':
				return self::is_choice( $value ) ? $value : new WP_Error( 'invalid_choice', __( 'Use one of Astra\'s layout ids, e.g. header-main-layout-1.', 'emcp-tools' ) );
			case 'array':
				return self::responsive_size( $value );
		}
		return new WP_Error( 'invalid_value', __( 'This setting cannot be written.', 'emcp-tools' ) );
	}

	/**
	 * A responsive font size in Astra's shape: desktop/tablet/mobile numbers (or
	 * "") and px/em/rem/% units, as Astra's sanitize_responsive_typo() stores it.
	 *
	 * @param mixed $value Raw value.
	 * @return array|WP_Error
	 */
	private static function responsive_size( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_size', __( 'Use { desktop, tablet, mobile, desktop-unit, tablet-unit, mobile-unit }.', 'emcp-tools' ) );
		}
		$out = array();
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			$size = $value[ $device ] ?? '';
			if ( '' !== $size && ! ( is_int( $size ) || is_float( $size ) || ( is_string( $size ) && preg_match( '/^\d{1,4}(?:\.\d{1,3})?$/D', $size ) ) ) ) {
				return new WP_Error( 'invalid_size', __( 'Sizes must be numbers (or "" for none).', 'emcp-tools' ) );
			}
			$out[ $device ] = is_string( $size ) && '' !== $size ? 0 + $size : $size;
		}
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			$unit = $value[ $device . '-unit' ] ?? 'px';
			if ( ! in_array( $unit, array( '', 'px', 'em', 'rem', '%' ), true ) ) {
				return new WP_Error( 'invalid_size', __( 'Units must be px, em, rem or %.', 'emcp-tools' ) );
			}
			$out[ $device . '-unit' ] = $unit;
		}
		return $out;
	}

	/**
	 * Astra refreshes its cached dynamic CSS on customize_save_after only, not on
	 * a plain option write, so invalidate it explicitly via Astra's own helper.
	 */
	private function refresh_astra_cache(): void {
		if ( function_exists( 'astra_clear_all_assets_cache' ) ) {
			astra_clear_all_assets_cache();
		}
	}
}
