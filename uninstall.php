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

$log_dir = trailingslashit( plugin_dir_path( __FILE__ ) . 'log' );
if ( is_dir( $log_dir ) ) {
    foreach ( (array) glob( $log_dir . '*' ) as $file ) {
        if ( is_file( $file ) ) {
            unlink( $file );
        }
    }
    rmdir( $log_dir );
}
