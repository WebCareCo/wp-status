<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'webcare_log_schedule_frequency' );
delete_option( 'webcare_log_custom_days' );

$log_dir = trailingslashit( plugin_dir_path( __FILE__ ) . 'log' );
if ( is_dir( $log_dir ) ) {
    foreach ( (array) glob( $log_dir . '*' ) as $file ) {
        if ( is_file( $file ) ) {
            unlink( $file );
        }
    }
    rmdir( $log_dir );
}
