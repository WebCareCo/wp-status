<?php
/*
Plugin Name: WebCare WP Status
Description: Save important system information into the database in JSON format, including firewall activity from NinjaFirewall and Defender.
Version: 1.13
Author: WebCare
Author URI: https://webcare.co
Requires at least: 5.5
Requires PHP: 7.4
Text Domain: wp-status
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Include the functions file
require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'show_log.php';

// Create the admin menu under Tools
function wp_system_info_saver_menu() {
    global $webcare_wp_status_hook;
    $webcare_wp_status_hook = add_management_page(
        'WebCare WP Status',
        'WebCare WP Status',
        'manage_options',
        'wp_status',
        'wp_status_page'
    );
}
add_action('admin_menu', 'wp_system_info_saver_menu');

// Only load our stylesheet on the plugin's own admin page
function wp_status_enqueue_assets($hook) {
    global $webcare_wp_status_hook;
    if ( $hook !== $webcare_wp_status_hook ) {
        return;
    }
    wp_enqueue_style(
        'webcare-wp-status-admin',
        plugin_dir_url(__FILE__) . 'assets/admin.css',
        array(),
        '1.13'
    );
}
add_action('admin_enqueue_scripts', 'wp_status_enqueue_assets');

// Add a Settings link on the Plugins page for WP Status
function wp_status_add_settings_link($links) {
    $settings_link = '<a href="' . esc_url( admin_url('tools.php?page=wp_status') ) . '">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wp_status_add_settings_link');

// Display admin page
function wp_status_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-status' ) );
    }

    // Handle log deletion
    if ( isset( $_GET['delete_log'] ) ) {
        $log_file = sanitize_file_name( wp_unslash( $_GET['delete_log'] ) );
        check_admin_referer( 'webcare_delete_log_' . $log_file );

        $log_dir       = webcare_wp_status_log_dir();
        $log_dir_real  = realpath( $log_dir );
        $log_path_real = realpath( $log_dir . $log_file );

        if ( $log_dir_real && $log_path_real && 0 === strpos( $log_path_real, $log_dir_real ) && 'json' === strtolower( pathinfo( $log_path_real, PATHINFO_EXTENSION ) ) ) {
            unlink( $log_path_real );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Log file deleted successfully!', 'wp-status' ) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Log file not found!', 'wp-status' ) . '</p></div>';
        }
    }

    // Handle clear all logs
    if ( isset( $_POST['clear_logs'] ) ) {
        check_admin_referer( 'webcare_clear_logs', 'webcare_wp_status_nonce' );

        $log_dir = webcare_wp_status_log_dir();
        foreach ( (array) glob( $log_dir . '*.json' ) as $file ) {
            unlink( $file );
        }
        echo '<div class="notice notice-success"><p>' . esc_html__( 'All log files cleared!', 'wp-status' ) . '</p></div>';
    }

    // Handle saving the auto-delete retention period
    if ( isset( $_POST['save_log_retention'] ) ) {
        check_admin_referer( 'webcare_save_retention', 'webcare_wp_status_nonce' );

        $retention_days = isset( $_POST['log_retention_days'] ) ? absint( $_POST['log_retention_days'] ) : 0;
        update_option( 'webcare_log_retention_days', $retention_days );

        $delete_on_uninstall = isset( $_POST['delete_on_uninstall'] ) ? 1 : 0;
        update_option( 'webcare_delete_on_uninstall', $delete_on_uninstall );

        if ( $retention_days > 0 ) {
            $message = sprintf( __( 'Logs older than %d day(s) will now be auto-deleted.', 'wp-status' ), $retention_days );
        } else {
            $message = __( 'Auto-delete turned off. Logs will be kept indefinitely.', 'wp-status' );
        }

        $message .= ' ' . ( $delete_on_uninstall
            ? __( 'Logs and settings will be deleted when this plugin is uninstalled.', 'wp-status' )
            : __( 'Logs and settings will be kept if this plugin is uninstalled.', 'wp-status' ) );

        echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
    }

    // Handle purging old logs on demand
    if ( isset( $_POST['purge_old_logs'] ) ) {
        check_admin_referer( 'webcare_purge_logs', 'webcare_wp_status_nonce' );

        $retention_days = (int) get_option( 'webcare_log_retention_days', 90 );
        if ( $retention_days > 0 ) {
            $deleted = webcare_wp_status_purge_old_logs( $retention_days );
            echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( 'Purged %d log file older than %d day(s).', 'Purged %d log files older than %d day(s).', $deleted, 'wp-status' ), $deleted, $retention_days ) ) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Set a retention period above 0 days before purging.', 'wp-status' ) . '</p></div>';
        }
    }

    // Handle API key regeneration
    if ( isset( $_POST['regenerate_api_key'] ) ) {
        check_admin_referer( 'webcare_regenerate_api_key', 'webcare_wp_status_nonce' );

        update_option( 'webcare_wp_status_api_key', webcare_wp_status_generate_api_key() );
        echo '<div class="notice notice-success"><p>' . esc_html__( 'API key regenerated. Any site pulling from this one will need the new key.', 'wp-status' ) . '</p></div>';
    }

    // Get the current schedule frequency and custom days from the database
    $current_frequency = get_option('webcare_log_schedule_frequency', 'weekly');
    $custom_days = get_option('webcare_log_custom_days', 7); // Default 7 days for custom
    $retention_days = (int) get_option('webcare_log_retention_days', 90); // default: auto-delete after 90 days
    $delete_on_uninstall = (int) get_option('webcare_delete_on_uninstall', 0); // default: keep data on uninstall

    // Installs active before the remote-access feature was added won't have a key yet
    $api_key = get_option( 'webcare_wp_status_api_key' );
    if ( ! $api_key ) {
        $api_key = webcare_wp_status_generate_api_key();
        update_option( 'webcare_wp_status_api_key', $api_key );
    }


    ?>
    <div class="wrap webcare-wp-status">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-chart-bar"></span>
            WebCare WP Status
        </h1>
        <p class="description">Capture a snapshot of your site's system info, content counts, and plugin/theme setup — on demand or on a schedule. Logs are stored in <code>wp-content/webcare-wp-status-logs</code>, outside the plugin folder, so they survive plugin updates.</p>

        <hr class="wp-header-end">

        <?php webcare_wp_status_show_log(); ?>

        <hr>

        <h2 class="webcare-wp-status-settings-heading"><span class="dashicons dashicons-admin-generic"></span> Settings</h2>

        <div class="webcare-wp-status-columns">

            <div class="card webcare-wp-status-card">
                <h2><span class="dashicons dashicons-update"></span> Generate a Log</h2>
                <p class="description">Click below to capture a fresh snapshot right now. It only takes a few seconds.</p>
                <form method="post" action="">
                    <?php wp_nonce_field( 'webcare_create_log', 'webcare_wp_status_nonce' ); ?>
                    <?php submit_button('Create a New Log', 'primary', 'save_system_info', false); ?>
                </form>
                <p class="webcare-wp-status-next-run"><?php webcare_wp_status_scheduled_run(); ?></p>
            </div>

            <div class="card webcare-wp-status-card">
                <h2><span class="dashicons dashicons-calendar-alt"></span> Automatic Schedule</h2>
                <p class="description">Choose how often a new log should be generated automatically.</p>
                <form method="post" action="">
                    <?php wp_nonce_field( 'webcare_save_schedule', 'webcare_wp_status_nonce' ); ?>
                    <p>
                        <label>
                            <input type="radio" name="schedule_frequency" value="daily" <?php checked($current_frequency, 'daily'); ?>>
                            Daily
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="schedule_frequency" value="weekly" <?php checked($current_frequency, 'weekly'); ?>>
                            Weekly (default)
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="schedule_frequency" value="monthly" <?php checked($current_frequency, 'monthly'); ?>>
                            Monthly
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="schedule_frequency" value="custom" <?php checked($current_frequency, 'custom'); ?>>
                            Custom interval (every <input type="number" name="custom_days" value="<?php echo esc_attr($custom_days); ?>" min="1" style="width: 60px;"> days)
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="schedule_frequency" value="manual" <?php checked($current_frequency, 'manual'); ?>>
                            Manual (turned off)
                        </label>
                    </p>
                    <?php submit_button('Save Schedule', 'secondary', 'save_schedule_frequency', false); ?>
                </form>
            </div>

            <div class="card webcare-wp-status-card">
                <h2><span class="dashicons dashicons-trash"></span> Log Retention</h2>
                <p class="description">Automatically delete log files older than a set number of days. Set to 0 to keep logs forever.</p>
                <form method="post" action="">
                    <?php wp_nonce_field( 'webcare_save_retention', 'webcare_wp_status_nonce' ); ?>
                    <p>
                        <label>
                            Delete logs older than
                            <input type="number" name="log_retention_days" value="<?php echo esc_attr($retention_days); ?>" min="0" style="width: 60px;">
                            days
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="delete_on_uninstall" value="1" <?php checked($delete_on_uninstall, 1); ?>>
                            Delete all logs and settings when this plugin is uninstalled
                        </label>
                    </p>
                    <?php submit_button('Save Retention', 'secondary', 'save_log_retention', false); ?>
                </form>

                <form method="post" action="" style="margin-top: 10px;">
                    <?php wp_nonce_field( 'webcare_purge_logs', 'webcare_wp_status_nonce' ); ?>
                    <?php submit_button('Purge Old Logs Now', 'secondary', 'purge_old_logs', false); ?>
                </form>

                <form method="post" action="" style="margin-top: 10px;">
                    <?php wp_nonce_field( 'webcare_clear_logs', 'webcare_wp_status_nonce' ); ?>
                    <?php submit_button('Clear All Logs', 'delete', 'clear_logs', false); ?>
                </form>
            </div>

            <div class="card webcare-wp-status-card">
                <h2><span class="dashicons dashicons-admin-network"></span> Remote Access</h2>
                <p class="description">Lets another site pull this site's latest 3 logs over the REST API. Keep this key secret — anyone who has it can read this site's plugin, theme, and version inventory.</p>
                <p>
                    <label for="webcare_wp_status_api_key_field">API Key</label>
                    <span class="webcare-wp-status-key-row">
                        <input type="password" id="webcare_wp_status_api_key_field" value="<?php echo esc_attr( $api_key ); ?>" readonly class="webcare-wp-status-key-input">
                        <button type="button" class="button" onclick="var f=document.getElementById('webcare_wp_status_api_key_field');var shown=(f.type==='text');f.type=shown?'password':'text';this.textContent=shown?'Show Key':'Hide Key';if(!shown){f.focus();f.select();}">Show Key</button>
                    </span>
                </p>
                <form method="post" action="" onsubmit="return confirm('Regenerating will break any site currently pulling with the old key. Continue?');">
                    <?php wp_nonce_field( 'webcare_regenerate_api_key', 'webcare_wp_status_nonce' ); ?>
                    <?php submit_button('Regenerate Key', 'secondary', 'regenerate_api_key', false); ?>
                </form>
            </div>

        </div>

        <p class="webcare-wp-status-footer">
            Made by <a href="https://webcare.co">WebCare — WordPress Maintenance</a>. Helping you manage your WordPress better.
        </p>
    </div>
    <?php
}
