<?php

function show_wp_status_log(){
        // Directory for logs
        $log_dir = plugin_dir_path(__FILE__) . 'log/';
        
        // Check if the log folder exists, create if not
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        // Display all JSON logs in a table
        $log_files = glob($log_dir . '*.json');
        usort($log_files, function($a, $b) {
            return filemtime($b) - filemtime($a); // Descending order
        });

        if ($log_files) {
            echo '<h2>System Info Logs</h2>';
            echo '<table class="widefat fixed">';
            echo '<thead><tr><th>Date</th><th>Systems</th><th>Pages and Posts</th><th>Plugins</th><th>Front Page Files</th><th>Folder Size</th><th>Users Count</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($log_files as $file) {
                $json_data = file_get_contents($file);
                $data = json_decode($json_data, true);

                if ($data) {
                    $filename = basename($file);
                    echo '<tr>';
                    echo '<td>' . date('d M Y h:ia', strtotime($data['date'])) . '</td>';
                    
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
                    echo '<td>';
                    
                    // Add Download link for the JSON file
                    $download_url = plugin_dir_url(__FILE__) . 'log/' . $filename;
                    echo '<a href="' . esc_url($download_url) . '" class="button" download>Download JSON</a> ';

                    echo '<a href="' . esc_url(add_query_arg('delete_log', $filename)) . '" class="button button-danger">Delete</a></td>';
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
}
        ?>