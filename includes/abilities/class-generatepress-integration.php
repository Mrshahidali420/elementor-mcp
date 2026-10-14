<?php
/**
 * GeneratePress theme-settings pack.
 *
 * `generatepress-read`  : get-settings ({ group?, keys? }): curated GP settings + metadata
 * `generatepress-write` : update-settings ({ values }): allowlisted, type-checked writes; skipped[] and rejected for the rest
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
	 * Curated allowlist: key => { type, label, group, shape }, plus the select
	 * choices and number bounds update-settings enforces. Verified against
	 * GeneratePress 3.6.1; the choices match its Customizer (3.5.1 source).
	 *
	 * @var array<string,array>
	 */
	const ALLOWLIST = array(
		'global_colors'         => array( 'type' => 'array',  'label' => 'Global color palette', 'group' => 'palette', 'shape' => 'Array of { name, slug, color }: the palette other colors reference as var(--slug), e.g. var(--accent). Slugs use a-z, 0-9 and hyphens.' ),
		'background_color'      => array( 'type' => 'color',  'label' => 'Body background',       'group' => 'colors', 'shape' => 'Hex (#fff or #ffffff), rgb(), rgba() or a palette var, e.g. "var(--base-2)". "" resets it.' ),
		'text_color'           => array( 'type' => 'color',  'label' => 'Body text color',       'group' => 'colors', 'shape' => 'Hex, rgb(), rgba() or a palette var, e.g. "var(--contrast)".' ),
		'link_color'           => array( 'type' => 'color',  'label' => 'Link color',            'group' => 'colors', 'shape' => 'Hex, rgb(), rgba() or a palette var, e.g. "var(--accent)".' ),
		'link_color_hover'     => array( 'type' => 'color',  'label' => 'Link hover color',      'group' => 'colors', 'shape' => 'Hex, rgb(), rgba() or a palette var.' ),
		'container_width'      => array( 'type' => 'number', 'label' => 'Container width (px)',  'group' => 'layout', 'shape' => 'Integer px, e.g. 1200.', 'min' => 200, 'max' => 5000 ),
		'content_layout_setting' => array( 'type' => 'select', 'label' => 'Content layout',      'group' => 'layout', 'shape' => '"separate-containers" | "one-container".', 'choices' => array( 'separate-containers', 'one-container' ) ),
		'container_alignment'  => array( 'type' => 'select', 'label' => 'Container alignment',   'group' => 'layout', 'shape' => '"boxes" | "text".', 'choices' => array( 'boxes', 'text' ) ),
		'body_font_size'       => array( 'type' => 'number', 'label' => 'Body font size (px)',   'group' => 'typography', 'shape' => 'Integer px; null when the theme default applies. With dynamic typography (the GeneratePress 3.x default) it is the body rule of the typography list.', 'min' => 6, 'max' => 200 ),
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
		if ( 'body_font_size' === $key ) {
			return $this->read_body_font_size();
		}
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

		$updated  = array();
		$skipped  = array();
		$rejected = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( self::ALLOWLIST[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}
			// GeneratePress prints these values into its inline CSS, and this write
			// bypasses its Customizer sanitizers, so each value is checked here.
			$clean = $this->sanitize_value( $key, $value );
			if ( is_wp_error( $clean ) ) {
				$rejected[ $key ] = $clean->get_error_message();
				continue;
			}
			if ( 'body_font_size' === $key ) {
				$option = self::with_body_font_size( $option, $clean );
			} else {
				$option[ $key ] = $clean;
			}
			$updated[] = $key;
		}

		if ( ! empty( $updated ) ) {
			update_option( self::OPTION, $option );
			$this->flush_dynamic_css();
		}

		return array(
			'updated'  => $updated,
			'skipped'  => $skipped,
			'rejected' => $rejected,
		);
	}

	/**
	 * A value as GeneratePress's Customizer would store it, or an error naming why
	 * it is refused.
	 *
	 * @param string $key   Allowlisted key.
	 * @param mixed  $value Raw value.
	 * @return mixed|WP_Error
	 */
	private function sanitize_value( string $key, $value ) {
		$meta = self::ALLOWLIST[ $key ];
		switch ( $meta['type'] ) {
			case 'color':
				$color = self::sanitize_color( $value );
				return null === $color ? new WP_Error( 'invalid_color', __( 'Use a hex color (#fff or #ffffff), rgb(), rgba(), a palette var such as var(--accent), or "" to reset.', 'emcp-tools' ) ) : $color;
			case 'number':
				if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d{1,5}$/D', $value ) ) ) {
					return new WP_Error( 'invalid_number', __( 'Use a whole number of pixels.', 'emcp-tools' ) );
				}
				$number = (int) $value;
				if ( $number < $meta['min'] || $number > $meta['max'] ) {
					/* translators: 1: smallest value, 2: largest value. */
					return new WP_Error( 'invalid_number', sprintf( __( 'Use a value from %1$d to %2$d.', 'emcp-tools' ), $meta['min'], $meta['max'] ) );
				}
				return $number;
			case 'select':
				if ( ! is_string( $value ) || ! in_array( $value, $meta['choices'], true ) ) {
					/* translators: %s: the allowed values. */
					return new WP_Error( 'invalid_choice', sprintf( __( 'Use one of: %s.', 'emcp-tools' ), implode( ', ', $meta['choices'] ) ) );
				}
				return $value;
			case 'array':
				return self::sanitize_palette( $value );
		}
		return new WP_Error( 'invalid_value', __( 'This setting cannot be written.', 'emcp-tools' ) );
	}

	/**
	 * A color GeneratePress accepts (see EMCP_Tools_Theme_Integration::css_color()).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function sanitize_color( $value ): ?string {
		return self::css_color( $value );
	}

	/**
	 * The global palette as GeneratePress stores it: a list of { name, slug, color }
	 * with a-z/0-9/hyphen slugs, unique, and colors that pass sanitize_color().
	 *
	 * @param mixed $value Raw value.
	 * @return array|WP_Error
	 */
	private static function sanitize_palette( $value ) {
		if ( ! is_array( $value ) || count( $value ) > 100 ) {
			return new WP_Error( 'invalid_palette', __( 'Use a list of up to 100 { name, slug, color } entries.', 'emcp-tools' ) );
		}
		$out   = array();
		$slugs = array();
		foreach ( array_values( $value ) as $i => $entry ) {
			$slug  = is_array( $entry ) && is_string( $entry['slug'] ?? null ) ? $entry['slug'] : '';
			$color = is_array( $entry ) ? self::sanitize_color( $entry['color'] ?? null ) : null;
			if ( ! preg_match( '/^[a-z0-9-]{1,50}$/D', $slug ) || isset( $slugs[ $slug ] ) || null === $color || '' === $color || 0 === strpos( $color, 'var(' ) ) {
				/* translators: %d: entry number, from 1. */
				return new WP_Error( 'invalid_palette', sprintf( __( 'Palette entry %d needs a unique slug of a-z, 0-9 and hyphens and a hex, rgb() or rgba() color.', 'emcp-tools' ), $i + 1 ) );
			}
			$slugs[ $slug ] = true;
			$name           = sanitize_text_field( is_string( $entry['name'] ?? null ) ? $entry['name'] : '' );
			$out[]          = array(
				'name'  => '' === $name ? $slug : $name,
				'slug'  => $slug,
				'color' => $color,
			);
		}
		return $out;
	}

	/**
	 * GeneratePress 3.x prints typography from the `typography` list when dynamic
	 * typography is on (the default since 3.1; sites upgraded from older versions
	 * keep it off) and ignores the legacy `body_font_size` key then.
	 *
	 * @param array $option The generate_settings option.
	 * @return bool
	 */
	private static function dynamic_typography( array $option ): bool {
		if ( function_exists( 'generate_is_using_dynamic_typography' ) ) {
			return (bool) generate_is_using_dynamic_typography();
		}
		return ! array_key_exists( 'use_dynamic_typography', $option ) || (bool) $option['use_dynamic_typography'];
	}

	/**
	 * Index of the core body rule in the typography list, or null.
	 *
	 * @param array $rules Typography rules.
	 * @return int|null
	 */
	private static function body_rule( array $rules ): ?int {
		foreach ( $rules as $i => $rule ) {
			if ( is_array( $rule ) && 'body' === ( $rule['selector'] ?? '' ) && 'core' === ( $rule['module'] ?? 'core' ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * The body font size in px, or null when none is set (the theme default
	 * applies) or the body rule uses another unit.
	 *
	 * @return int|null
	 */
	private function read_body_font_size(): ?int {
		$option = get_option( self::OPTION, array() );
		$option = is_array( $option ) ? $option : array();
		if ( self::dynamic_typography( $option ) ) {
			$rules = is_array( $option['typography'] ?? null ) ? $option['typography'] : array();
			$i     = self::body_rule( $rules );
			if ( null === $i || ! is_numeric( $rules[ $i ]['fontSize'] ?? '' ) || ! in_array( $rules[ $i ]['fontSizeUnit'] ?? 'px', array( '', 'px' ), true ) ) {
				return null;
			}
			return (int) $rules[ $i ]['fontSize'];
		}
		$size = $option['body_font_size'] ?? ( function_exists( 'generate_get_default_fonts' ) ? ( generate_get_default_fonts()['body_font_size'] ?? null ) : null );
		return is_numeric( $size ) ? (int) $size : null;
	}

	/**
	 * The option with the body font size set where GeneratePress reads it.
	 *
	 * @param array $option The generate_settings option.
	 * @param int   $size   Size in px.
	 * @return array
	 */
	private static function with_body_font_size( array $option, int $size ): array {
		if ( ! self::dynamic_typography( $option ) ) {
			$option['body_font_size'] = $size;
			return $option;
		}
		$rules = is_array( $option['typography'] ?? null ) ? array_values( $option['typography'] ) : array();
		$i     = self::body_rule( $rules );
		if ( null === $i ) {
			$rules[] = array(
				'selector' => 'body',
				'module'   => 'core',
				'group'    => 'base',
			);
			$i       = count( $rules ) - 1;
		}
		// GeneratePress's Customizer stores sizes as strings.
		$rules[ $i ]['fontSize']     = (string) $size;
		$rules[ $i ]['fontSizeUnit'] = 'px';
		$option['typography']        = $rules;
		return $option;
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
