<?php
// Isolated uninstall harness: WordPress runs the uninstaller without EMCP's
// bootstrap, so only class-uninstaller.php is loaded here and it must bring
// every class its cleanup steps need. Prints PASS or the failure.
define( 'ABSPATH', sys_get_temp_dir() . '/emcp-uninstall-' . getmypid() . '/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'EMCP_TOOLS_DIR', dirname( __DIR__, 2 ) . '/' );
@mkdir( WP_CONTENT_DIR . '/emcp-sandbox/widgets/7', 0777, true );
file_put_contents( WP_CONTENT_DIR . '/emcp-sandbox/widgets/7/widget.php', '<?php // generated' );
// Safe updates: a finished job folder (with a leftover snapshot) and one still recovering.
foreach ( array( 'done' => 'completed', 'live' => 'verify' ) as $emcp_job => $emcp_phase ) {
	@mkdir( ABSPATH . '.emcp-update-' . $emcp_job . '/snapshot-0', 0777, true );
	file_put_contents( ABSPATH . '.emcp-update-' . $emcp_job . '/snapshot-0/file-0.php', "<?php exit; ?>\nx" );
	file_put_contents( ABSPATH . '.emcp-update-' . $emcp_job . '/state.php', "<?php exit; ?>\n" . json_encode( array( 'phase' => $emcp_phase ) ) );
}
file_put_contents( ABSPATH . '.emcp-update-lock.php', '' );
// Filesystem tool backups (copies of edited PHP files) and WP-CLI job output.
foreach ( array( 'emcp-fs-backups', 'emcp-wpcli-jobs' ) as $emcp_dir ) {
	@mkdir( WP_CONTENT_DIR . '/uploads/' . $emcp_dir, 0777, true );
	file_put_contents( WP_CONTENT_DIR . '/uploads/' . $emcp_dir . '/1700000000-functions.php', '<?php // copy' );
}
$GLOBALS['emcp_multisite'] = in_array( 'multisite', $argv, true );
function is_multisite() { return $GLOBALS['emcp_multisite']; }
function get_sites( $args = array() ) { return array( 1, 2 ); }
function switch_to_blog( $id ) { $GLOBALS['wpdb']->prefix = 1 === (int) $id ? 'wp_' : 'wp_' . $id . '_'; $GLOBALS['wpdb']->options = $GLOBALS['wpdb']->prefix . 'options'; $GLOBALS['switched'][] = $id; return true; }
function restore_current_blog() { $GLOBALS['wpdb']->prefix = 'wp_'; $GLOBALS['wpdb']->options = 'wp_options'; return true; }
function wp_normalize_path( $p ) { return str_replace( chr( 92 ), '/', $p ); }
function wp_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR . '/uploads' ); }

$GLOBALS['deleted'] = array();
function delete_option( $k ) { $GLOBALS['deleted'][] = 'option:' . $k; return true; }
function delete_transient( $k ) { $GLOBALS['deleted'][] = 'transient:' . $k; return true; }
function delete_metadata( $type, $id, $key = '', ...$a ) { $GLOBALS['deleted'][] = 'meta:' . $key; return true; }
function wp_delete_post( $id, $force = false ) { $GLOBALS['deleted'][] = 'post:' . $id; return true; }
function apply_filters( $tag, $value ) { return $value; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function current_user_can( $c ) { return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['deleted'][] = 'cron:' . $hook; return 0; }
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $a = null ) { return true; }
function get_posts( $args = array() ) { return array(); }
function delete_post_meta( ...$a ) { return true; }
function get_users( $args = array() ) { return array(); }
function _get_cron_array() { return array( 1700000000 => array( 'emcp_tools_changes_prune' => array(), 'emcp_tools_oauth_gc' => array(), 'tribe_events_cron' => array(), 'emcp_backup_cron' => array(), 'emcp_restore_cron' => array() ), 1700000600 => array( 'emcp_tools_scheduled_backup' => array() ) ); }
function wp_unschedule_hook( $hook ) { $GLOBALS['deleted'][] = 'cron:' . $hook; return 1; }
class WP_Query {
	public $posts;
	public function __construct( $args ) { $type = $args['post_type'] ?? ''; $this->posts = 'emcp_widget' === $type ? array( 7 ) : ( 'emcp_theme_php' === $type ? array( 9 ) : array() ); }
}
class Fake_WPDB {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $usermeta = 'wp_usermeta';
	public $queries = array();
	public function prepare( $q, ...$a ) { $a = is_array( $a[0] ?? null ) ? $a[0] : $a; return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function query( $q ) { $this->queries[] = $q; return 0; }
	public function get_var( $q ) { return null; }
	public function get_col( $q ) { return array(); }
	public function get_results( $q ) { return array(); }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require EMCP_TOOLS_DIR . 'includes/class-uninstaller.php';
try {
	EMCP_Tools_Uninstaller::run();
} catch ( Throwable $e ) {
	echo 'FAIL ', get_class( $e ), ': ', $e->getMessage(), "\n";
	exit( 1 );
}
$sql = implode( "\n", $GLOBALS['wpdb']->queries );
$checks = array(
	'widget posts deleted'      => in_array( 'post:7', $GLOBALS['deleted'], true ),
	'widget sandbox file gone'  => ! file_exists( WP_CONTENT_DIR . '/emcp-sandbox/widgets/7/widget.php' ),
	'oauth clients table drop'  => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_oauth_clients' ),
	'oauth tokens table drop'   => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_oauth_tokens' ),
	'oauth lease rows deleted'  => str_contains( $sql, "LIKE 'emcp\\_tools\\_lease\\_oauth\\_%'" ),
	'ai chat options deleted'   => in_array( 'option:emcp_tools_ai_models', $GLOBALS['deleted'], true ),
	'drop emcp_changes' => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_changes' ),
	'drop emcp_change_blobs' => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_change_blobs' ),
	'drop emcp_redirects' => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_redirects' ),
	'drop emcp_search_index' => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_search_index' ),
	'drop emcp_migrate_backups' => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_migrate_backups' ),
	'drop emcp_migrate_jobs' => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_migrate_jobs' ),
	'drop emcp_migrate_targets' => str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_migrate_targets' ),
	'emcp_tools_ options and transients' => str_contains( $sql, "option_name LIKE 'emcp\_tools\_%'" ) && str_contains( $sql, "option_name LIKE '\_transient\_emcp\_tools\_%'" ) && str_contains( $sql, "option_name LIKE '\_transient\_timeout\_emcp\_tools\_%'" ),
	'config journals' => str_contains( $sql, "option_name LIKE 'emcp\_config\_operation\_%'" ),
	'builder lock options' => in_array( 'option:emcp_divi_settings_lock', $GLOBALS['deleted'], true ) && in_array( 'option:emcp_oxygen_import_lock', $GLOBALS['deleted'], true ),
	'emcp_tools_ user meta' => str_contains( $sql, "meta_key LIKE 'emcp\_tools\_%'" ),
	'emcp cron hooks cleared' => in_array( 'cron:emcp_tools_changes_prune', $GLOBALS['deleted'], true ) && in_array( 'cron:emcp_tools_oauth_gc', $GLOBALS['deleted'], true ) && in_array( 'cron:emcp_tools_scheduled_backup', $GLOBALS['deleted'], true ),
	'other plugins cron kept' => ! in_array( 'cron:tribe_events_cron', $GLOBALS['deleted'], true ),
	'finished safe-update job removed' => ! file_exists( ABSPATH . '.emcp-update-done' ),
	'filesystem backups removed' => ! file_exists( WP_CONTENT_DIR . '/uploads/emcp-fs-backups' ),
	'wp-cli job output removed' => ! file_exists( WP_CONTENT_DIR . '/uploads/emcp-wpcli-jobs' ),
	'other user meta removed' => in_array( 'meta:_emcp_tools_read_notifications', $GLOBALS['deleted'], true ) && in_array( 'meta:emcp_ai_chat_prompt_usage', $GLOBALS['deleted'], true ),
	'themer php templates deleted' => in_array( 'post:9', $GLOBALS['deleted'], true ),
	'migrate cron hooks cleared' => in_array( 'cron:emcp_backup_cron', $GLOBALS['deleted'], true ) && in_array( 'cron:emcp_restore_cron', $GLOBALS['deleted'], true ),
	'per-page builder locks' => str_contains( $sql, "option_name LIKE 'emcp\_bricks\_lock\_%'" ) && str_contains( $sql, "option_name LIKE 'emcp\_beaver\_lock\_%'" ),
	'every site cleaned on multisite' => ! $GLOBALS['emcp_multisite'] || ( str_contains( $sql, 'DROP TABLE IF EXISTS wp_2_emcp_changes' ) && str_contains( $sql, 'DROP TABLE IF EXISTS wp_emcp_changes' ) && array( 1, 2 ) === $GLOBALS['switched'] ),
	'recovering safe-update job kept' => is_file( ABSPATH . '.emcp-update-live/snapshot-0/file-0.php' ) && is_file( ABSPATH . '.emcp-update-lock.php' ),
);
foreach ( $checks as $name => $ok ) {
	if ( ! $ok ) {
		echo "FAIL $name\n";
		exit( 1 );
	}
}
echo "PASS\n";
