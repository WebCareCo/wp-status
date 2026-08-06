<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Only remove data if the user opted in via the "Delete all logs and
// settings when this plugin is uninstalled" checkbox. Default is to keep it.
if ( ! get_option( 'webcare_delete_on_uninstall', 0 ) ) {
    return;
}

delete_option( 'webcare_log_schedule_frequency' );
delete_option( 'webcare_log_custom_days' );
delete_option( 'webcare_log_retention_days' );
delete_option( 'webcare_delete_on_uninstall' );
delete_option( 'webcare_wp_status_log_dir_migrated' );

function webcare_wp_status_uninstall_delete_dir( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    foreach ( (array) glob( $dir . '*' ) as $file ) {
        if ( is_file( $file ) ) {
            unlink( $file );
        }
    }
    rmdir( $dir );
}

// Current location (wp-content/webcare-wp-status-logs), plus the pre-1.11 in-plugin
// location as a fallback in case this site was updated and deleted before ever
// loading wp-admin (i.e. before the migration in functions.php had a chance to run).
webcare_wp_status_uninstall_delete_dir( trailingslashit( WP_CONTENT_DIR ) . 'webcare-wp-status-logs/' );
webcare_wp_status_uninstall_delete_dir( trailingslashit( plugin_dir_path( __FILE__ ) . 'log' ) );
