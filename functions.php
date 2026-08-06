<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

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

// Count attached CSS and JS on the main page, as seen by a logged-out visitor.
function wp_system_info_saver_count_assets_on_home() {
    $response = wp_remote_get(
        home_url( '/' ),
        array(
            'timeout'   => 15,
            'sslverify' => false,
        )
    );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return array(
            'css' => 0,
            'js'  => 0,
        );
    }

    $html = wp_remote_retrieve_body( $response );

    $css_count = preg_match_all( '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', $html );
    $js_count  = preg_match_all( '/<script\b[^>]*src=["\'][^"\']+["\'][^>]*>/i', $html );

    return array(
        'css' => (int) $css_count,
        'js'  => (int) $js_count,
    );
}

// Write files that block direct access to the log folder (Apache, IIS, and directory listing).
function webcare_wp_status_protect_log_dir( $log_dir ) {
    if ( ! is_dir( $log_dir ) ) {
        return;
    }

    $htaccess = $log_dir . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
    }

    $webconfig = $log_dir . 'web.config';
    if ( ! file_exists( $webconfig ) ) {
        file_put_contents( $webconfig, "<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n" );
    }

    $index = $log_dir . 'index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }
}

// Save system info when form is submitted
function wp_system_info_saver_save_info($manual_trigger = false) { //added manual trigger
    if ($manual_trigger || isset($_POST['save_system_info'])) {

        if ( ! $manual_trigger ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            check_admin_referer( 'webcare_create_log', 'webcare_wp_status_nonce' );
        }

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

        // Break users down by role: Administrator, Editor, everyone else, and any
        // user left with no role at all ("hidden" — e.g. orphaned multisite users).
        $user_counts     = count_users();
        $avail_roles     = isset($user_counts['avail_roles']) ? $user_counts['avail_roles'] : array();
        $total_users     = isset($user_counts['total_users']) ? (int) $user_counts['total_users'] : 0;
        $admin_users     = isset($avail_roles['administrator']) ? (int) $avail_roles['administrator'] : 0;
        $editor_users    = isset($avail_roles['editor']) ? (int) $avail_roles['editor'] : 0;
        $named_roles_sum = array_sum($avail_roles);
        $other_users     = max(0, $named_roles_sum - $admin_users - $editor_users);
        $hidden_users    = max(0, $total_users - $named_roles_sum);

        $data = array(
            'date' => current_time('mysql'),
            'wordpress_version' => get_bloginfo('version') ?: 'Unknown',
            'php_version' => phpversion() ?: 'Unknown',
            'mysql_version' => $wpdb->db_version() ?: 'Unknown',
            'theme' => wp_get_theme()->get('Name') ?: 'Unknown',
            'theme_status' => wp_get_theme()->exists() ? 'Active' : 'Inactive',
            'parent_theme' => wp_get_theme()->parent() ? wp_get_theme()->parent()->get('Name') : 'None',
            'parent_theme_version' => wp_get_theme()->parent() ? wp_get_theme()->parent()->get('Version') : 'None',
            'plugins' => get_plugins() ?: array(),
            'plugins_count' => count(get_plugins() ?: array()),
            'active_plugins' => get_option('active_plugins') ?: array(),
            'active_plugins_count' => count(get_option('active_plugins') ?: array()),
            'inactive_plugins' => array_diff(array_keys(get_plugins() ?: array()), get_option('active_plugins') ?: array()),
            'inactive_plugins_count' => count(array_diff(array_keys(get_plugins() ?: array()), get_option('active_plugins') ?: array())),
            'posts_count' => isset($posts_count) ? $posts_count : 0,
            'pages_count' => isset($pages_count) ? $pages_count : 0,
            'pages_draft_count' => isset($pages_draft_count) ? $pages_draft_count : 0,
            'posts_draft_count' => isset($posts_draft_count) ? $posts_draft_count : 0,
            'published_custom_posts' => isset($published_custom_posts) ? $published_custom_posts : array(),
            'cpt_count' => isset($total_posts) ? ($total_posts - ($posts_count + $pages_count)) : 0,
            'css_js_count' => function_exists('wp_system_info_saver_count_assets_on_home') ? wp_system_info_saver_count_assets_on_home() : 0,
            'wp_folder_size' => function_exists('wp_system_info_saver_folder_size') ? wp_system_info_saver_folder_size(ABSPATH) : 0,
            'plugin_folder_size' => function_exists('wp_system_info_saver_folder_size') ? wp_system_info_saver_folder_size(WP_PLUGIN_DIR) : 0,
            'upload_folder_size' => function_exists('wp_system_info_saver_folder_size') ? wp_system_info_saver_folder_size(wp_get_upload_dir()['basedir']) : 0,
            'db_size' => function_exists('wp_system_info_saver_db_size') ? wp_system_info_saver_db_size() : 0,
            'users_count' => array(
                'total'  => $total_users,
                'admin'  => $admin_users,
                'editor' => $editor_users,
                'others' => $other_users,
                'hidden' => $hidden_users,
            ),
        );


        $json_data = json_encode($data);

        // Create log folder if not exists
        $log_dir = trailingslashit( plugin_dir_path(__FILE__) . 'log' );
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        webcare_wp_status_protect_log_dir( $log_dir );

        // Generate filename with website host and date (sanitized so subdirectory installs can't break the path)
        $site_host = sanitize_file_name( preg_replace( '#^https?://#', '', site_url() ) );
        $file_name = $site_host . '-' . current_time('Y-m-d-H-i-s') . '-system-log.json';
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

// Hook into the form submission for manual trigger
add_action('admin_post_save_system_info', 'wp_system_info_saver_save_info');

// Stream a log file to the browser after verifying capability, nonce, and path.
add_action( 'admin_post_webcare_download_log', 'webcare_wp_status_download_log' );
function webcare_wp_status_download_log() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'wp-status' ) );
    }

    $file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
    check_admin_referer( 'webcare_download_log_' . $file );

    $log_dir       = trailingslashit( plugin_dir_path( __FILE__ ) . 'log' );
    $log_dir_real  = realpath( $log_dir );
    $log_path_real = realpath( $log_dir . $file );

    if ( ! $log_dir_real || ! $log_path_real || 0 !== strpos( $log_path_real, $log_dir_real ) || 'json' !== strtolower( pathinfo( $log_path_real, PATHINFO_EXTENSION ) ) ) {
        wp_die( esc_html__( 'Log file not found.', 'wp-status' ) );
    }

    nocache_headers();
    header( 'Content-Type: application/json' );
    header( 'Content-Disposition: attachment; filename="' . basename( $log_path_real ) . '"' );
    header( 'Content-Length: ' . filesize( $log_path_real ) );
    readfile( $log_path_real );
    exit;
}

// Delete this site's log files older than $days. Returns how many were removed.
function webcare_wp_status_purge_old_logs( $days ) {
    $days = (int) $days;
    if ( $days <= 0 ) {
        return 0;
    }

    $log_dir   = trailingslashit( plugin_dir_path( __FILE__ ) . 'log' );
    $site_host = sanitize_file_name( preg_replace( '#^https?://#', '', site_url() ) );
    $cutoff    = time() - ( $days * DAY_IN_SECONDS );
    $deleted   = 0;

    foreach ( (array) glob( $log_dir . $site_host . '-*.json' ) as $file ) {
        if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
            unlink( $file );
            $deleted++;
        }
    }

    return $deleted;
}

// Function to generate log and store it via WP Cron
function webcare_generate_log() {
    wp_system_info_saver_save_info(true); // Pass true to indicate it's manually triggered

    // Keep the log folder tidy: purge anything past the configured retention window.
    $retention_days = (int) get_option( 'webcare_log_retention_days', 90 );
    if ( $retention_days > 0 ) {
        webcare_wp_status_purge_old_logs( $retention_days );
    }
}

// Register the cron event hook
add_action('webcare_generate_log_event', 'webcare_generate_log');

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
        'display'  => sprintf(
            /* translators: %d: number of days between scheduled logs. */
            _n( 'Every %d day', 'Every %d days', $days, 'wp-status' ),
            $days
        ),
    );
    return $schedules;
}

// Handle schedule frequency update (admin-only, capability + nonce checked)
add_action( 'admin_init', 'webcare_wp_status_handle_schedule_save' );
function webcare_wp_status_handle_schedule_save() {
    if ( ! isset( $_POST['save_schedule_frequency'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    check_admin_referer( 'webcare_save_schedule', 'webcare_wp_status_nonce' );

    $schedule_frequency = isset( $_POST['schedule_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_frequency'] ) ) : 'weekly';
    update_option('webcare_log_schedule_frequency', $schedule_frequency);

    if ($schedule_frequency === 'custom') {
        $custom_days = isset( $_POST['custom_days'] ) ? absint( $_POST['custom_days'] ) : 7;
        $custom_days = max( 1, $custom_days );
        update_option('webcare_log_custom_days', $custom_days);
    }

    // Update cron schedule based on the selected frequency
    webcare_update_cron_schedule($schedule_frequency);
}

// Initialize the cron schedule when plugin is activated
register_activation_hook(__FILE__, function() {
    $frequency = get_option('webcare_log_schedule_frequency', 'weekly'); // Default to weekly
    webcare_update_cron_schedule($frequency);

    add_option( 'webcare_log_retention_days', 90 ); // Only sets it if it doesn't already exist

    $log_dir = trailingslashit( plugin_dir_path( __FILE__ ) . 'log' );
    if ( ! is_dir( $log_dir ) ) {
        mkdir( $log_dir, 0755, true );
    }
    webcare_wp_status_protect_log_dir( $log_dir );
});

// Clear scheduled cron event when plugin is deactivated
register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('webcare_generate_log_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'webcare_generate_log_event');
    }
});

function webcare_wp_status_scheduled_run(){
    // Get the next scheduled event timestamp for the cron job
    $next_schedule_time = wp_next_scheduled('webcare_generate_log_event');
    if ($next_schedule_time) {
        $current_time = time();
        $time_difference = $next_schedule_time - $current_time;

        if ($time_difference > 0) {
            // Calculate hours, minutes, and seconds
            $hours = floor($time_difference / 3600);
            $minutes = floor(($time_difference % 3600) / 60);
            $seconds = $time_difference % 60;

            echo "<p>Time left to generate log: $hours hours, $minutes minutes, and $seconds seconds</p>";
        } else {
            echo "<p>The next log will be generated soon.</p>";
        }
    } else {
        echo "<p>No scheduled log generation.</p>";
    }
}
