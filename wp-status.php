<?php
/*
Plugin Name: WebCare WP Status
Description: Save important system information into the database in JSON format.
Version: 1.6
Author: WebCare
Author URI: https://webcare.co
*/

// Include the functions file
require_once plugin_dir_path(__FILE__) . 'functions.php';

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

    ?>
    <div class="wrap">
        <h1>WebCare Info Logger</h1>
        <p>Click the button below to create a new log. Please wait a few seconds to generate.</p>
        <p>All log files are stored in /log folder. Making it quicker to generate and retrieved.</p>
        
        <form method="post" action="">
            <?php submit_button('Create a New Log', 'primary', 'save_system_info'); ?>
        </form>

        <hr>

        <?php
        // Directory for logs
        $log_dir = plugin_dir_path(__FILE__) . 'log/';
        
        // Check if the log folder exists, create if not
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        // Display all JSON logs in a table
        $log_files = glob($log_dir . '*.json');

        if ($log_files) {
            echo '<h2>System Info Logs</h2>';
            echo '<table class="widefat fixed">';
            echo '<thead><tr><th>Date</th><th>Systems</th><th>Pages and Posts</th><th>Plugins</th><th>CSS/JS Count</th><th>Folder Size</th><th>Users Count</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($log_files as $file) {
                $json_data = file_get_contents($file);
                $data = json_decode($json_data, true);

                if ($data) {
                    $filename = basename($file);
                    echo '<tr>';
                    echo '<td>' . date('d M Y', strtotime($data['date'])) . '</td>';
                    
                    // Combine systems information
                    echo '<td>';
                    echo 'WordPress Version: ' . esc_html($data['wordpress_version']) . '<br>';
                    echo 'PHP Version: ' . esc_html($data['php_version']) . '<br>';
                    echo 'MySQL Version: ' . esc_html($data['mysql_version']) . '<br>';
                    echo 'Theme: ' . esc_html($data['theme']) . ' (' . esc_html($data['theme_status']) . ')<br>';
                    echo 'Parent Theme: ' . esc_html($data['parent_theme']) . ' (' . esc_html($data['parent_theme_version']) . ')';
                    echo '</td>';
                    
                    // Combine posts and pages information
                    echo '<td>';
                    echo 'Page Published: ' . esc_html($data['pages_count']) . '<br>';
                    echo 'Page Draft: ' . esc_html($data['pages_draft_count']) . '<br>';
                    echo 'Post Published: ' . esc_html($data['posts_count']) . '<br>';
                    echo 'Post Draft: ' . esc_html($data['posts_draft_count']) .'<br>';
                    echo 'Published Custom Posts: ' . esc_html($data['published_custom_posts']); // Add this line
                    echo '</td>';

                    // Expand plugins information
                    echo '<td>';
                    echo 'Total Plugins: ' . esc_html($data['plugins_count']) . '<br>';
                    echo 'Active Plugins: ' . esc_html($data['active_plugins_count']) . '<br>';
                    echo 'Inactive Plugins: ' . esc_html($data['inactive_plugins_count']);
                    echo '</td>';
                    
                    echo '<td>CSS: ' . esc_html($data['css_js_count']['css']) . ' / JS: ' . esc_html($data['css_js_count']['js']) . '</td>';
                    echo '<td>';
                    echo 'WP Folder Size: '. esc_html($data['wp_folder_size']) . '<br>';
                    echo 'Plugin Size: ' . esc_html($data['plugin_folder_size']) . '<br>';
                    echo 'Media Size: ' . esc_html($data['upload_folder_size']) . '<br>';
                    echo 'Database Size: ' . esc_html($data['db_size']);
                    echo '</td>';
                    echo '<td>' . esc_html($data['users_count']) . '</td>';
                    echo '<td><a href="' . esc_url(add_query_arg('delete_log', $filename)) . '" class="button button-danger">Delete</a></td>';
                    echo '</tr>';
                }
            }

            echo '</tbody>';
            echo '</table>';
            ?>

            <form method="post" action="">
                <?php submit_button('Clear All Logs', 'secondary', 'clear_logs'); ?>
            </form>

            <?php
        } else {
            echo '<p>No log files found.</p>';
        }
        ?>
    <hr>
    <p>Made by <a href="https://webcare.co">WebCare - WordPress Maintenance</a> Helping you manage your WordPress better</p>
    </div>
    <?php
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