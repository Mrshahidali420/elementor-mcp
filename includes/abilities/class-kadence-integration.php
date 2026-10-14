<?php
/**
 * Kadence theme settings pack.
 *
 * `kadence-read`  : get-settings ({ group?, keys? }) — curated Kadence settings + metadata
 * `kadence-write` : update-settings ({ values }): allowlisted theme_mod writes (the palette goes to the kadence_global_palette option), skipped[] and rejected for the rest
 *
 * Registers only when Kadence is the active theme. Kadence stores its customizer
 * settings as theme_mods and reads them through `Kadence\kadence()->option()`
 * (theme_mod + built-in default); writing is a plain `set_theme_mod()`. Values are
 * structured objects that reference a 9-slot global palette (palette1..9); each
 * allowlist entry carries a `shape` hint so an agent knows what to send. Kadence
 * renders dynamic CSS inline per request, so there is no cache to invalidate.
 *
 * @package EMCP_Tools
 * @since   3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kadence theme settings integration.
 */
class EMCP_Tools_Kadence_Integration extends EMCP_Tools_Theme_Integration {

	/** Kadence keeps its palette in this option as a JSON string, not in a theme_mod. */
	const PALETTE_OPTION = 'kadence_global_palette';

	/** The three palettes Kadence can switch between. */
	const PALETTES = array( 'palette', 'second-palette', 'third-palette' );

	/**
	 * Curated allowlist: key => { type, label, group, shape }. Verified against
	 * Kadence 1.5.2 on the dev site (live option() shapes). Extend as more groups
	 * are curated.
	 *
	 * @var array<string,array{type:string,label:string,group:string,shape:string}>
	 */
	const ALLOWLIST = array(
		// palette (the design's color source; colors elsewhere reference palette1..9)
		'global_palette'         => array( 'type' => 'palette', 'label' => 'Global color palette', 'group' => 'palette', 'shape' => 'Kadence\'s kadence_global_palette option: { palette: [ { color, slug, name }, ... ], "second-palette": [...], "third-palette": [...], active: "palette" | "second-palette" | "third-palette" }. Each palette lists 9 to 15 slots in order, slug "palette1".."palette15", color as hex, rgb() or rgba(). Send only the palettes you change; the rest are kept. Slots are referenced elsewhere as "palette1".."palette9".' ),
		// colors
		'link_color'             => array( 'type' => 'object', 'label' => 'Link colors', 'group' => 'colors', 'shape' => '{ highlight: "palette1", "highlight-alt": "palette2", "highlight-alt2": "palette9", style: "standard" } — values are palette slugs or CSS colors.' ),
		// typography
		'base_font'              => array( 'type' => 'object', 'label' => 'Body font', 'group' => 'typography', 'shape' => '{ size:{desktop,tablet?,mobile?}, sizeType:"px", lineHeight:{desktop}, family, google:bool, weight, variant, color:"palette4" }' ),
		'heading_font'           => array( 'type' => 'object', 'label' => 'Headings font (global family)', 'group' => 'typography', 'shape' => '{ family:"inherit"|"Font Name", google:bool }' ),
		'h1_font'                => array( 'type' => 'object', 'label' => 'H1 font', 'group' => 'typography', 'shape' => '{ size:{desktop}, lineHeight:{desktop}, family, weight, variant, color:"palette3" }' ),
		'h2_font'                => array( 'type' => 'object', 'label' => 'H2 font', 'group' => 'typography', 'shape' => 'as h1_font' ),
		'h3_font'                => array( 'type' => 'object', 'label' => 'H3 font', 'group' => 'typography', 'shape' => 'as h1_font' ),
		'h4_font'                => array( 'type' => 'object', 'label' => 'H4 font', 'group' => 'typography', 'shape' => 'as h1_font' ),
		'h5_font'                => array( 'type' => 'object', 'label' => 'H5 font', 'group' => 'typography', 'shape' => 'as h1_font' ),
		'h6_font'                => array( 'type' => 'object', 'label' => 'H6 font', 'group' => 'typography', 'shape' => 'as h1_font' ),
		// layout
		'content_width'          => array( 'type' => 'object', 'label' => 'Content width', 'group' => 'layout', 'shape' => '{ size:1290, unit:"px" }' ),
		'page_layout'            => array( 'type' => 'string', 'label' => 'Default page layout', 'group' => 'layout', 'shape' => '"normal" | "fullwidth" | "left-sidebar" | "right-sidebar"' ),
		// buttons
		'buttons_background'     => array( 'type' => 'object', 'label' => 'Button colors', 'group' => 'buttons', 'shape' => '{ color:"palette1", hover:"palette2" } — palette slugs or CSS colors.' ),
		'buttons_typography'     => array( 'type' => 'object', 'label' => 'Button typography', 'group' => 'buttons', 'shape' => '{ size:{desktop}, family, weight, variant }' ),
		'buttons_border_radius'  => array( 'type' => 'object', 'label' => 'Button border radius', 'group' => 'buttons', 'shape' => '{ size:{desktop,tablet,mobile}, unit:{desktop:"px",...} }' ),
		// header / footer
		'header_main_background' => array( 'type' => 'object', 'label' => 'Header background', 'group' => 'header-footer', 'shape' => '{ desktop:{ color:"palette1" } }' ),
		'footer_wrap_background' => array( 'type' => 'object', 'label' => 'Footer background', 'group' => 'header-footer', 'shape' => '{ desktop:{ color:"palette9" } }' ),
	);

	public function id(): string {
		return 'kadence';
	}

	public function label(): string {
		return __( 'Kadence', 'emcp-tools' );
	}

	public function is_available(): bool {
		return 'kadence' === get_template();
	}

	protected function operations(): array {
		return array(
			'get-settings'    => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_get_settings' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'Read curated Kadence settings with value + type/label/group/shape metadata. Optional { group } (palette|colors|typography|layout|buttons|header-footer) or { keys: [...] }; no arg returns all. Colors reference the global palette slugs palette1..9.', 'emcp-tools' ),
			),
			'update-settings' => array(
				'mode' => 'write',
				'run'  => array( $this, 'execute_update_settings' ),
				'perm' => array( $this, 'can_write' ),
				'desc' => __( 'Write curated Kadence settings ({ values: { key: value } }) as theme_mods; global_palette goes to the kadence_global_palette option. Non-allowlisted keys are reported in skipped[]. Values are structured objects — read the shape from get-settings first.', 'emcp-tools' ),
			),
		);
	}

	/**
	 * @param array $input { group?: string, keys?: string[] }.
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
	 * The effective value of a key: Kadence's own resolver (theme_mod or built-in
	 * default) when available, else the raw theme_mod, else null.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private function read_value( string $key ) {
		if ( 'global_palette' === $key ) {
			return self::stored_palette();
		}
		if ( function_exists( 'Kadence\\kadence' ) ) {
			return \Kadence\kadence()->option( $key );
		}
		return get_theme_mod( $key, null );
	}

	/**
	 * @param array $input { values: { key: value } }.
	 * @return array|WP_Error
	 */
	public function execute_update_settings( $input ) {
		$values = ( isset( $input['values'] ) && is_array( $input['values'] ) ) ? $input['values'] : array();
		if ( empty( $values ) ) {
			return new WP_Error( 'missing_values', __( 'Provide a "values" object of Kadence setting key => value pairs.', 'emcp-tools' ), array( 'status' => 400 ) );
		}

		$updated  = array();
		$skipped  = array();
		$rejected = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( self::ALLOWLIST[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}
			if ( 'palette' === self::ALLOWLIST[ $key ]['type'] ) {
				$palette = self::merged_palette( $value );
				if ( is_wp_error( $palette ) ) {
					$rejected[ $key ] = $palette->get_error_message();
					continue;
				}
				update_option( self::PALETTE_OPTION, wp_json_encode( $palette ) );
				$updated[] = $key;
				continue;
			}
			// Kadence prints these into its inline CSS and set_theme_mod() runs none
			// of its Customizer sanitizers, so each value is checked here.
			if ( 'string' === self::ALLOWLIST[ $key ]['type'] ? ! self::is_choice( $value ) : ! ( is_array( $value ) && self::is_safe_structure( $value ) ) ) {
				$rejected[ $key ] = 'string' === self::ALLOWLIST[ $key ]['type']
					? __( 'Use one of the listed values, e.g. "normal".', 'emcp-tools' )
					: __( 'Use the object shape from get-settings. Values must be palette slugs, hex, rgb(), rgba(), var(--name), plain http(s) URLs, numbers or plain text without ; { } < > \\ @ : or parentheses.', 'emcp-tools' );
				continue;
			}
			set_theme_mod( $key, $value );
			$updated[] = $key;
		}

		return array(
			'updated'  => $updated,
			'skipped'  => $skipped,
			'rejected' => $rejected,
		);
	}

	/**
	 * The stored palette, or Kadence's default when none is saved yet.
	 *
	 * @return array|null
	 */
	private static function stored_palette(): ?array {
		$raw = get_option( self::PALETTE_OPTION );
		if ( ( ! is_string( $raw ) || '' === $raw ) && function_exists( 'Kadence\\kadence' ) ) {
			$raw = \Kadence\kadence()->get_default_palette();
		}
		$palette = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $palette ) ? $palette : null;
	}

	/**
	 * The stored palette with the sent palettes and active choice applied, or an
	 * error naming what Kadence could not use. Kadence finds a slot by its
	 * position and then checks the slug, so slots must be in order.
	 *
	 * @param mixed $value Raw value.
	 * @return array|WP_Error
	 */
	private static function merged_palette( $value ) {
		if ( ! is_array( $value ) || empty( $value ) || array_diff( array_keys( $value ), array_merge( self::PALETTES, array( 'active' ) ) ) ) {
			/* translators: %s: the allowed keys. */
			return new WP_Error( 'invalid_palette', sprintf( __( 'Send an object with any of: %s.', 'emcp-tools' ), implode( ', ', array_merge( self::PALETTES, array( 'active' ) ) ) ) );
		}
		$palette = self::stored_palette() ?? array();
		foreach ( self::PALETTES as $name ) {
			if ( ! array_key_exists( $name, $value ) ) {
				continue;
			}
			$slots = $value[ $name ];
			if ( ! is_array( $slots ) || count( $slots ) < 9 || count( $slots ) > 15 ) {
				/* translators: %s: palette name. */
				return new WP_Error( 'invalid_palette', sprintf( __( '"%s" needs 9 to 15 slots.', 'emcp-tools' ), $name ) );
			}
			$clean = array();
			foreach ( array_values( $slots ) as $i => $slot ) {
				$slug  = 'palette' . ( $i + 1 );
				$color = is_array( $slot ) ? self::css_color( $slot['color'] ?? null ) : null;
				if ( ! is_array( $slot ) || $slug !== ( $slot['slug'] ?? $slug ) || null === $color || '' === $color || 0 === strpos( $color, 'var(' ) ) {
					/* translators: 1: palette name, 2: slot slug. */
					return new WP_Error( 'invalid_palette', sprintf( __( 'Slot %2$s of "%1$s" needs the slug %2$s and a hex, rgb() or rgba() color.', 'emcp-tools' ), $name, $slug ) );
				}
				$label   = sanitize_text_field( is_string( $slot['name'] ?? null ) ? $slot['name'] : '' );
				$clean[] = array(
					'color' => $color,
					'slug'  => $slug,
					'name'  => '' === $label ? $slug : $label,
				);
			}
			$palette[ $name ] = $clean;
		}
		if ( array_key_exists( 'active', $value ) ) {
			if ( ! in_array( $value['active'], self::PALETTES, true ) ) {
				/* translators: %s: the allowed values. */
				return new WP_Error( 'invalid_palette', sprintf( __( '"active" must be one of: %s.', 'emcp-tools' ), implode( ', ', self::PALETTES ) ) );
			}
			$palette['active'] = $value['active'];
		}
		if ( empty( $palette['active'] ) ) {
			$palette['active'] = 'palette';
		}
		return $palette;
	}
}
