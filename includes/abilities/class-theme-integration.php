<?php
/**
 * Abstract base for Themes-tab integrations.
 *
 * Each integration exposes exactly two MCP tools — one Read, one Write — that
 * dispatch to internal operations resolved by name (the ACF two-dispatcher
 * pattern). Concrete integrations implement id()/label()/is_available()/
 * operations() plus their execute_* methods; this base owns registration,
 * dispatch, the discovery catalog, and per-operation permission delegation.
 *
 * @package EMCP_Tools
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two-dispatcher base for a theme integration.
 */
abstract class EMCP_Tools_Theme_Integration {

	/**
	 * Short integration id, used to build the tool names (`<id>-read`/`-write`).
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * Human label for the integration (admin cards + tool labels).
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Whether this integration should register (e.g. its framework is active).
	 *
	 * @return bool
	 */
	abstract public function is_available(): bool;

	/**
	 * The operation map: name => { mode: 'read'|'write', run: callable,
	 * perm: callable():bool, desc: string }.
	 *
	 * @return array<string,array{mode:string,run:callable,perm:callable,desc:string}>
	 */
	abstract protected function operations(): array;

	/**
	 * @return string The read tool ability name.
	 */
	final public function read_tool(): string {
		return 'emcp-tools/' . $this->id() . '-read';
	}

	/**
	 * @return string The write tool ability name.
	 */
	final public function write_tool(): string {
		return 'emcp-tools/' . $this->id() . '-write';
	}

	/**
	 * @return string[] Both dispatcher tool names.
	 */
	final public function get_ability_names(): array {
		return array( $this->read_tool(), $this->write_tool() );
	}

	/**
	 * Register the read + write dispatcher tools.
	 */
	public function register(): void {
		emcp_tools_register_ability(
			$this->read_tool(),
			array(
				'label'               => $this->label() . ' Read',
				'description'         => $this->read_description(),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'run_read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'input_schema'        => $this->dispatch_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
		emcp_tools_register_ability(
			$this->write_tool(),
			array(
				'label'               => $this->label() . ' Write',
				'description'         => $this->write_description(),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'run_write' ),
				'permission_callback' => array( $this, 'can_write' ),
				'input_schema'        => $this->dispatch_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Read dispatcher callback.
	 *
	 * @param mixed $input Tool input.
	 * @return mixed
	 */
	public function run_read( $input ) {
		return $this->dispatch( 'read', $input );
	}

	/**
	 * Write dispatcher callback.
	 *
	 * @param mixed $input Tool input.
	 * @return mixed
	 */
	public function run_write( $input ) {
		return $this->dispatch( 'write', $input );
	}

	/**
	 * Tool-level gate (the MCP permission_callback). Individual operations
	 * re-check their own capability in dispatch(); this is the coarse gate so a
	 * disabled tool never dispatches.
	 *
	 * @return bool
	 */
	public function can_read(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * @return bool
	 */
	public function can_write(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Resolve + run an operation, or return the discovery catalog when no
	 * operation is given. Each op runs its own permission check (no escalation).
	 *
	 * @param string $mode  'read' or 'write'.
	 * @param mixed  $input Tool input ({ operation, arguments }).
	 * @return mixed
	 */
	private function dispatch( string $mode, $input ) {
		$input     = is_array( $input ) ? $input : array();
		$operation = isset( $input['operation'] ) ? (string) $input['operation'] : '';
		$ops       = $this->operations();

		if ( '' === $operation ) {
			return $this->catalog( $mode, $ops );
		}
		if ( ! isset( $ops[ $operation ] ) || $ops[ $operation ]['mode'] !== $mode ) {
			return new WP_Error(
				'unknown_operation',
				sprintf(
					/* translators: 1: mode (read/write), 2: operation name. */
					__( 'Unknown %1$s operation: %2$s', 'emcp-tools' ),
					$mode,
					$operation
				),
				array( 'status' => 404 )
			);
		}

		$op = $ops[ $operation ];
		if ( ! call_user_func( $op['perm'] ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission for this operation.', 'emcp-tools' ),
				array( 'status' => 403 )
			);
		}

		$args = ( isset( $input['arguments'] ) && is_array( $input['arguments'] ) ) ? $input['arguments'] : array();
		return call_user_func( $op['run'], $args );
	}

	/**
	 * The discovery catalog for a mode: op name + description.
	 *
	 * @param string $mode Mode.
	 * @param array  $ops  Operation map.
	 * @return array{mode:string,operations:array<int,array{operation:string,description:string}>}
	 */
	private function catalog( string $mode, array $ops ): array {
		$out = array();
		foreach ( $ops as $name => $op ) {
			if ( $op['mode'] === $mode ) {
				$out[] = array(
					'operation'   => $name,
					'description' => $op['desc'],
				);
			}
		}
		return array(
			'mode'         => $mode,
			'operations'   => $out,
			'capabilities' => $this->capabilities(),
		);
	}

	/**
	 * A normalized capabilities descriptor so an agent learns what a theme/block
	 * pack supports without special-casing each one. Subclasses override to
	 * declare support.
	 *
	 * - supports_patterns: has list-patterns / insert-pattern (an editor-valid
	 *   insertion route).
	 * - supports_preview: can render/validate a block before it is committed.
	 * - styles_model: how the pack is styled — 'none' (place-and-render),
	 *   'uniqueid' (self-styling by id), 'styles-object' (a styles map compiled to
	 *   CSS), or 'attributes' (attribute-driven).
	 *
	 * @return array{supports_patterns:bool,supports_preview:bool,styles_model:string}
	 */
	protected function capabilities(): array {
		return array(
			'supports_patterns' => false,
			'supports_preview'  => false,
			'styles_model'      => 'none',
		);
	}

	/**
	 * The shared { operation, arguments } input schema.
	 *
	 * @return array
	 */
	private function dispatch_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation' => array(
					'type'        => 'string',
					'description' => __( 'Operation name. Omit to list the available operations.', 'emcp-tools' ),
				),
				'arguments' => array(
					'type'        => 'object',
					'description' => __( 'Arguments for the operation.', 'emcp-tools' ),
				),
			),
		);
	}

	/**
	 * @return string
	 */
	protected function read_description(): string {
		return $this->label() . ', read operations. Call with no operation to list them.';
	}

	/**
	 * @return string
	 */
	protected function write_description(): string {
		return $this->label() . ', write operations. Call with no operation to list them.';
	}

	// ---------------------------------------------------------------------
	// Value checks for theme settings writes. Themes print these settings
	// into inline CSS, and a settings write here bypasses the theme's own
	// Customizer sanitizers, so no value may close a declaration, a rule or
	// the style element, or load anything.
	// ---------------------------------------------------------------------

	/**
	 * A CSS color as themes store it, normalized, or null: "" (reset), #rgb,
	 * #rrggbb, rgb(r,g,b), rgba(r,g,b,a) or var(--name).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	protected static function css_color( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$color = trim( $value );
		if ( '' === $color || preg_match( '/^#(?:[0-9a-f]{3}){1,2}$/Di', $color ) || preg_match( '/^var\(--[a-z0-9-]{1,60}\)$/Di', $color ) ) {
			return $color;
		}
		$flat = str_replace( ' ', '', $color );
		if ( preg_match( '/^rgb\((\d{1,3}),(\d{1,3}),(\d{1,3})\)$/D', $flat, $m ) && max( (int) $m[1], (int) $m[2], (int) $m[3] ) <= 255 ) {
			return $flat;
		}
		if ( preg_match( '/^rgba\((\d{1,3}),(\d{1,3}),(\d{1,3}),(0|1|0?\.\d+|1\.0+)\)$/D', $flat, $m ) && max( (int) $m[1], (int) $m[2], (int) $m[3] ) <= 255 ) {
			return $flat;
		}
		return null;
	}

	/**
	 * A font-family list ("'Inter', sans-serif", inherit, Roboto) or null:
	 * letters, digits, spaces, commas, hyphens, underscores, dots and quotes only.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	protected static function font_family( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$family = trim( $value );
		return strlen( $family ) <= 200 && preg_match( '/^[A-Za-z0-9 ,\'"._-]*$/D', $family ) ? $family : null;
	}

	/**
	 * A choice value (layout ids and the like): lowercase letters, digits,
	 * hyphens and underscores.
	 *
	 * @param mixed $value Raw value.
	 */
	protected static function is_choice( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_-]{1,60}$/D', $value );
	}

	/**
	 * Whether a structured value (nested settings objects) is safe to store:
	 * identifier keys, finite numbers, booleans, null, and strings that are a
	 * css_color(), a plain http(s) URL, or plain text without ; { } < > \ @ :
	 * and without parentheses. At most 6 levels and 500 values.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $depth Current depth.
	 * @param int   $count Values seen so far.
	 */
	protected static function is_safe_structure( $value, int $depth = 0, int &$count = 0 ): bool {
		if ( ++$count > 500 || $depth > 6 ) {
			return false;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 500 ) {
				return false;
			}
			if ( null !== self::css_color( $value ) ) {
				return true;
			}
			if ( preg_match( '#^https?://[A-Za-z0-9._~/?\#%=&+-]+$#D', $value ) ) {
				return true;
			}
			return 1 === preg_match( '/^[^;{}<>\\\\@:()]*$/D', $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( ! is_int( $key ) && ! preg_match( '/^[A-Za-z0-9_-]{1,40}$/D', (string) $key ) ) {
					return false;
				}
				if ( ! self::is_safe_structure( $item, $depth + 1, $count ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * A whole-number size from an int or a digit string, within bounds, or null.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Smallest.
	 * @param int   $max   Largest.
	 */
	protected static function whole_number( $value, int $min, int $max ): ?int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d{1,6}$/D', $value ) ) ) {
			return null;
		}
		$n = (int) $value;
		return $n >= $min && $n <= $max ? $n : null;
	}
}
