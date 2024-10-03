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
