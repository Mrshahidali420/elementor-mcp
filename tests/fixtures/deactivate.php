<?php
// Isolated deactivation harness: recurring EMCP maintenance events stop when the
// plugin is deactivated, the user's backup schedule and one-off events stay, and
// other plugins' events are untouched. Prints PASS or the failure.
define( 'ABSPATH', sys_get_temp_dir() . '/emcp-deactivate-' . getmypid() . '/' );
define( 'EMCP_TOOLS_DIR', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['emcp_multisite'] = in_array( 'multisite', $argv, true );
$GLOBALS['site']           = 1;
$GLOBALS['cleared']        = array();
$GLOBALS['cron']           = array(
	1 => array(
		1700000000 => array(
			'emcp_tools_changes_prune'    => array( 'k' => array( 'schedule' => 'daily', 'args' => array() ) ),
			'emcp_tools_memory_rollup'    => array( 'k' => array( 'schedule' => 'hourly', 'args' => array() ) ),
			'emcp_tools_scheduled_backup' => array( 'k' => array( 'schedule' => 'daily', 'args' => array() ) ),
			'emcp_tools_refresh_ai_models' => array( 'k' => array( 'schedule' => 'weekly', 'args' => array() ) ),
			'emcp_tools_backup_worker'    => array( 'k' => array( 'schedule' => false, 'args' => array( 12 ) ) ),
			'tribe_events_cron'           => array( 'k' => array( 'schedule' => 'hourly', 'args' => array() ) ),
		),
	),
	2 => array(
		1700000000 => array(
			'emcp_tools_oauth_gc' => array( 'k' => array( 'schedule' => 'daily', 'args' => array() ) ),
		),
	),
);
function is_multisite() { return $GLOBALS['emcp_multisite']; }
function get_sites( $args = array() ) { return array( 1, 2 ); }
function switch_to_blog( $id ) { $GLOBALS['site'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['site'] = 1; return true; }
function _get_cron_array() { return $GLOBALS['cron'][ $GLOBALS['site'] ] ?? array(); }
function wp_unschedule_hook( $hook ) { $GLOBALS['cleared'][] = $GLOBALS['site'] . ':' . $hook; return 1; }

require EMCP_TOOLS_DIR . 'includes/class-deactivator.php';
EMCP_Tools_Deactivator::run( $GLOBALS['emcp_multisite'] );

$c      = $GLOBALS['cleared'];
$checks = array(
	'daily maintenance cleared'    => in_array( '1:emcp_tools_changes_prune', $c, true ),
	'hourly maintenance cleared'   => in_array( '1:emcp_tools_memory_rollup', $c, true ),
	'backup schedule kept'         => ! in_array( '1:emcp_tools_scheduled_backup', $c, true ),
	'model refresh kept'           => ! in_array( '1:emcp_tools_refresh_ai_models', $c, true ),
	'one-off events kept'          => ! in_array( '1:emcp_tools_backup_worker', $c, true ),
	'other plugins kept'           => ! in_array( '1:tribe_events_cron', $c, true ),
	'network sites follow'         => $GLOBALS['emcp_multisite'] ? in_array( '2:emcp_tools_oauth_gc', $c, true ) : ! in_array( '2:emcp_tools_oauth_gc', $c, true ),
);
foreach ( $checks as $name => $ok ) {
	if ( ! $ok ) {
		echo "FAIL $name\n";
		exit( 1 );
	}
}
echo "PASS\n";
