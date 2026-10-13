<?php
/**
 * Deactivation cleanup.
 *
 * Clears EMCP's recurring maintenance events so they stop firing while the
 * plugin is off. Each one is scheduled again on the next load by its owner's
 * `wp_next_scheduled()` check, so reactivating behaves as before. Events that
 * only a user action schedules (see KEEP) stay, and so do one-off events such
 * as a backup worker tick or a restore token cleanup.
 *
 * @package EMCP_Tools
 * @since   3.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes recurring plugin cron events on deactivation.
 *
 * @since 3.20.0
 */
class EMCP_Tools_Deactivator {

	/**
	 * Recurring hooks that are scheduled only by a user action, not on every load,
	 * so clearing them would lose them: the backup schedule (set when it is saved)
	 * and the AI model list refresh (set when an AI key is saved).
	 */
	const KEEP = array( 'emcp_tools_scheduled_backup', 'emcp_tools_refresh_ai_models' );

	/**
	 * Runs on deactivation; a network deactivation covers every site.
	 *
	 * @param bool $network_wide Whether the plugin was network deactivated.
	 */
	public static function run( $network_wide = false ): void {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::clear_site();
				} finally {
					restore_current_blog();
				}
			}
			return;
		}
		self::clear_site();
	}

	/** Clears the current site's recurring emcp_tools_ events. */
	private static function clear_site(): void {
		$hooks = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				if ( ! is_string( $hook ) || 0 !== strpos( $hook, 'emcp_tools_' ) || in_array( $hook, self::KEEP, true ) ) {
					continue;
				}
				foreach ( (array) $instances as $event ) {
					if ( ! empty( $event['schedule'] ) ) {
						$hooks[ $hook ] = true;
					}
				}
			}
		}
		foreach ( array_keys( $hooks ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
}
