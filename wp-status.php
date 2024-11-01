<?php
/*
Plugin Name: WebCare WP Status
Description: Save important system information into the database in JSON format.
Version: 1.8
Author: WebCare
Author URI: https://webcare.co
*/

// Include the functions file
require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'show_log.php';

// Create the admin menu under Tools
function wp_system_info_saver_menu() {
    add_management_page(
        'WebCare WP Status',
        'WebCare WP Status',
        'manage_options',
        'wp_status',
        'wp_status_page'
    );
}
add_action('admin_menu', 'wp_system_info_saver_menu');

// Add a Settings link on the Plugins page for WP Status
function wp_status_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('tools.php?page=wp_status') . '">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wp_status_add_settings_link');

// Display admin page
function wp_status_page() {
    // Handle log deletion
    if (isset($_GET['delete_log'])) {
        $log_file = sanitize_text_field($_GET['delete_log']);
        $log_path = plugin_dir_path(__FILE__) . 'log/' . $log_file;

        if (file_exists($log_path)) {
            unlink($log_path);
            echo '<div class="notice notice-success"><p>Log file deleted successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Log file not found!</p></div>';
        }
    }

    // Handle clear all logs
    if (isset($_POST['clear_logs'])) {
        $log_dir = plugin_dir_path(__FILE__) . 'log/';
        array_map('unlink', glob("$log_dir*.json")); // Deletes all JSON files in the log folder
        echo '<div class="notice notice-success"><p>All log files cleared!</p></div>';
    }
    // Get the current schedule frequency and custom days from the database
    $current_frequency = get_option('webcare_log_schedule_frequency', 'weekly');
    $custom_days = get_option('webcare_log_custom_days', 7); // Default 7 days for custom


    ?>
    <div class="wrap">
        <h1>WebCare WP Status</h1>
        <hr>
        <p>Click the button below to create a new log. Please wait a few seconds to generate.</p>
        <p>All log files are stored in /log folder. Making it quicker to generate and retrieved.</p>
        
        <form method="post" action="">
            <?php submit_button('Create a New Log', 'primary', 'save_system_info'); ?>
        </form>
        <!-- Log Scheduling Form -->
        <h2>Log Scheduling Options</h2>
        <p>Select how often you want the logs to be automatically generated:</p>
        <form method="post" action="">
            <label>
                <input type="radio" name="schedule_frequency" value="daily" <?php checked($current_frequency, 'daily'); ?>>
                Daily
            </label><br>

            <label>
                <input type="radio" name="schedule_frequency" value="weekly" <?php checked($current_frequency, 'weekly'); ?>>
                Weekly (default)
            </label><br>

            <label>
                <input type="radio" name="schedule_frequency" value="monthly" <?php checked($current_frequency, 'monthly'); ?>>
                Monthly
            </label><br>

            <label>
                <input type="radio" name="schedule_frequency" value="custom" <?php checked($current_frequency, 'custom'); ?>>
                Custom Interval (every <input type="number" name="custom_days" value="<?php echo esc_attr($custom_days); ?>" min="1" style="width: 50px;"> days)
            </label><br>

            <label>
                <input type="radio" name="schedule_frequency" value="manual" <?php checked($current_frequency, 'manual'); ?>>
                Manual (turned off)
            </label><br>

            <?php submit_button('Save Schedule', 'secondary', 'save_schedule_frequency'); ?>
        </form>
        <!-- PHP Countdown Timer -->
    <?php scheduled_run(); ?>
        <hr>

        <!-- Your existing log table and buttons go here -->
        <?php show_wp_status_log(); ?>
        
    <hr>
    <p>Made by <a href="https://webcare.co">WebCare - WordPress Maintenance</a> Helping you manage your WordPress better</p>
    </div>
    <?php
}
