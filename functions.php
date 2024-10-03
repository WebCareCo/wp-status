<?php

// Calculate folder size
function wp_system_info_saver_folder_size($folder) {
    $total_size = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $total_size += $file->getSize();
    }
    return size_format($total_size);
}

// Calculate Database size
function wp_system_info_saver_db_size() {
    global $wpdb;
    $size = 0;
    $tables = $wpdb->get_results('SHOW TABLE STATUS');
    foreach ($tables as $table) {
        $size += $table->Data_length + $table->Index_length;
    }
    return size_format($size);
}

// Count attached CSS and JS on the main page
function wp_system_info_saver_count_assets_on_home() {
    // Start output buffering
    ob_start();

    // Temporarily switch to the main page context
    // If you're using a multisite, uncomment the line below
    // switch_to_blog(1); // 1 is usually the ID of the main site

    // Simulate the main page request
    $main_page_url = get_home_url();
    $request_uri = $_SERVER['REQUEST_URI'];
    $_SERVER['REQUEST_URI'] = '/'; // Simulate the main page

    // Load the WordPress environment
    wp_head(); // This will output all styles and scripts added to the head

    // Get the current global $wp_styles and $wp_scripts
    global $wp_styles, $wp_scripts;

    // Get the counts
    $css_count = count($wp_styles->queue); // Count styles queued for output
    $js_count = count($wp_scripts->queue); // Count scripts queued for output

    // Restore original REQUEST_URI
    $_SERVER['REQUEST_URI'] = $request_uri;

    // Clean the output buffer
    ob_end_clean();

    // If you're using a multisite, uncomment the line below
    // restore_current_blog();

    return array(
        'css' => $css_count,
        'js' => $js_count
    );
}

// Save system info when form is submitted
function wp_system_info_saver_save_info() {
    if (isset($_POST['save_system_info'])) {
        global $wpdb;

        // Calculate post counts
        $total_posts = wp_count_posts()->publish;
        $pages_count = wp_count_posts('page')->publish;
        $posts_count = wp_count_posts('post')->publish;
        $pages_draft_count = wp_count_posts('page')->draft;
        $posts_draft_count = wp_count_posts('post')->draft;

        // Count published custom posts
        $args = array(
            'public'   => true,
            '_builtin' => false
        );
        $output = 'names';
        $post_types = get_post_types($args, $output); 

        $published_custom_posts = 0; // Initialize the variable
        foreach ($post_types as $post_type) {
            $count_posts = wp_count_posts($post_type);
            $published_custom_posts += $count_posts->publish; // Accumulate published custom posts
        }

        $data = array(
            'date' => current_time('mysql'),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'mysql_version' => $wpdb->db_version(),
            'theme' => wp_get_theme()->get('Name'),
            'theme_status' => wp_get_theme()->exists() ? 'Active' : 'Inactive',
            'parent_theme' => wp_get_theme()->parent()->get('Name'),
            'parent_theme_version' => wp_get_theme()->parent()->get('Version'),
            'plugins' => get_plugins(),
            'plugins_count' => count(get_plugins()),
            'active_plugins' => get_option('active_plugins'),
            'active_plugins_count' => count(get_option('active_plugins')),
            'inactive_plugins' => array_diff(array_keys(get_plugins()), get_option('active_plugins')),
            'inactive_plugins_count' => count(array_diff(array_keys(get_plugins()), get_option('active_plugins'))),
            'posts_count' => $posts_count,
            'pages_count' => $pages_count,
            'pages_draft_count' => $pages_draft_count,
            'posts_draft_count' => $posts_draft_count,
            'published_custom_posts' => $published_custom_posts, // Add this line
            'cpt_count' => $total_posts - ($posts_count + $pages_count), // All posts minus pages and regular posts
            'css_js_count' => wp_system_info_saver_count_assets_on_home(),
            'wp_folder_size' => wp_system_info_saver_folder_size(ABSPATH),
            'plugin_folder_size' => wp_system_info_saver_folder_size(WP_PLUGIN_DIR),
            'upload_folder_size' => wp_system_info_saver_folder_size(wp_get_upload_dir()['basedir']),
            'db_size' => wp_system_info_saver_db_size(),
            'users_count' => count_users()['total_users'],
        );

        $json_data = json_encode($data);

        // Create log folder if not exists
        $log_dir = plugin_dir_path(__FILE__) . 'log/';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        // Generate filename with website URL and date
        $site_url = preg_replace('/https?:\/\//', '', site_url());
        $file_name = $site_url . '-' . date('Y-m-d-H-i-s') . '-system-log.json';
        $file_path = $log_dir . $file_name;

        // Save the JSON data to the log file
        file_put_contents($file_path, $json_data);

        // Show success notice
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>System info saved and logged!</p></div>';
        });
    }
}

add_action('admin_init', 'wp_system_info_saver_save_info');

// Version 1.6 up to here

// Register the cron event hook
add_action('webcare_generate_log_event', 'webcare_generate_log');

// Function to generate log and store it
function webcare_generate_log() {
    // Call your log creation function here (assumes it's inside your 'functions.php')
    webcare_save_system_info(); // Replace with your actual log generation function
}

// Function to schedule or unschedule cron based on frequency
function webcare_update_cron_schedule($frequency) {
    // Unschedule any existing cron job
    $timestamp = wp_next_scheduled('webcare_generate_log_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'webcare_generate_log_event');
    }

    // Schedule new cron event based on frequency
    if ($frequency === 'daily') {
        wp_schedule_event(time(), 'daily', 'webcare_generate_log_event');
    } elseif ($frequency === 'weekly') {
        wp_schedule_event(time(), 'weekly', 'webcare_generate_log_event');
    } elseif ($frequency === 'monthly') {
        wp_schedule_event(time(), 'monthly', 'webcare_generate_log_event');
    } elseif (is_numeric($frequency)) {
        // Custom interval (X days)
        wp_schedule_event(time(), 'custom_interval', 'webcare_generate_log_event');
    }
}

// Add custom interval for cron (for X days option)
add_filter('cron_schedules', 'webcare_add_custom_cron_interval');
function webcare_add_custom_cron_interval($schedules) {
    $days = get_option('webcare_log_custom_days', 7); // Default to 7 days if not set
    $schedules['custom_interval'] = array(
        'interval' => $days * DAY_IN_SECONDS,
        'display'  => __('Every ' . $days . ' days')
    );
    return $schedules;
}

// Handle schedule frequency update
if (isset($_POST['save_schedule_frequency'])) {
    $schedule_frequency = sanitize_text_field($_POST['schedule_frequency']);
    update_option('webcare_log_schedule_frequency', $schedule_frequency);

    if ($schedule_frequency === 'custom') {
        $custom_days = intval($_POST['custom_days']);
        update_option('webcare_log_custom_days', $custom_days);
    }

    // Update cron schedule based on the selected frequency
    webcare_update_cron_schedule($schedule_frequency);
}

// Initialize the cron schedule when plugin is activated
register_activation_hook(__FILE__, function() {
    $frequency = get_option('webcare_log_schedule_frequency', 'weekly'); // Default to weekly
    webcare_update_cron_schedule($frequency);
});

// Clear scheduled cron event when plugin is deactivated
register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('webcare_generate_log_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'webcare_generate_log_event');
    }
});
