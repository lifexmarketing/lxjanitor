<?php
/**
 * Remove LX Janitor's own data. Autoload changes are restored first so uninstalling never leaves options orphaned.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$changes = get_option( 'lxjanitor_autoload_changes', array() );
foreach ( (array) $changes as $name => $original ) {
	$wpdb->update( $wpdb->options, array( 'autoload' => $original ), array( 'option_name' => $name ) );
}
delete_option( 'lxjanitor_autoload_changes' );
delete_option( 'lxjanitor_history' );
delete_transient( 'lxjanitor_probe' );
delete_site_transient( 'lxjanitor_release' );
wp_cache_delete( 'alloptions', 'options' );
