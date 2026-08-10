<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Where logs live. NOT inside the plugin's own folder (wp-content/plugins/wp-status/log) —
// WordPress's plugin updater deletes and re-extracts the entire plugin directory on every
// update/reinstall, which was silently wiping this folder every time. wp-content itself is
// never touched by a plugin update, so logs stored here survive.
function webcare_wp_status_log_dir() {
    return trailingslashit( WP_CONTENT_DIR ) . 'webcare-wp-status-logs/';
}

// One-time move of any logs left behind in the old in-plugin location (pre-1.11) to the
// new location above, so upgrading doesn't strand or lose existing log history.
add_action( 'admin_init', 'webcare_wp_status_migrate_log_dir' );
function webcare_wp_status_migrate_log_dir() {
    if ( get_option( 'webcare_wp_status_log_dir_migrated' ) ) {
        return;
    }

    $old_dir = trailingslashit( plugin_dir_path( __FILE__ ) . 'log' );
    $new_dir = webcare_wp_status_log_dir();

    if ( is_dir( $old_dir ) ) {
        if ( ! is_dir( $new_dir ) ) {
            wp_mkdir_p( $new_dir );
        }
        webcare_wp_status_protect_log_dir( $new_dir );

        foreach ( (array) glob( $old_dir . '*.json' ) as $file ) {
            $destination = $new_dir . basename( $file );
            if ( ! file_exists( $destination ) ) {
                @rename( $file, $destination );
            }
        }

        foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $protect_file ) {
            $path = $old_dir . $protect_file;
            if ( file_exists( $path ) ) {
                @unlink( $path );
            }
        }

        if ( ! glob( $old_dir . '*' ) ) {
            @rmdir( $old_dir );
        }
    }

    update_option( 'webcare_wp_status_log_dir_migrated', 1 );
}

// Calculate folder size, in raw bytes (so it can be compared/summed later)
function wp_system_info_saver_folder_size($folder) {
    $total_size = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $total_size += $file->getSize();
    }
    return $total_size;
}

// Calculate Database size, in raw bytes
function wp_system_info_saver_db_size() {
    global $wpdb;
    $size = 0;
    $tables = $wpdb->get_results('SHOW TABLE STATUS');
    foreach ($tables as $table) {
        $size += $table->Data_length + $table->Index_length;
    }
    return $size;
}

// Logs written before this version stored these fields as already-formatted
// strings (e.g. "612 MB"); newer logs store raw bytes so they can be compared.
// Format either shape the same way for display.
function webcare_wp_status_format_size( $value ) {
    if ( is_numeric( $value ) ) {
        return size_format( (float) $value );
    }
    return (string) $value;
}

// Sum of the four size fields on a log entry, in bytes. Returns null if none
// of them are numeric (i.e. this is a pre-1.10 log stored as formatted strings).
function webcare_wp_status_total_bytes( $data ) {
    $fields  = array( 'wp_folder_size', 'plugin_folder_size', 'upload_folder_size', 'db_size' );
    $total   = 0;
    $has_any = false;

    foreach ( $fields as $field ) {
        if ( isset( $data[ $field ] ) && is_numeric( $data[ $field ] ) ) {
            $total  += (float) $data[ $field ];
            $has_any = true;
        }
    }

    return $has_any ? $total : null;
}

// Render a compact up/down/flat/changed indicator comparing $current to $previous.
// kind: 'number' (plain count), 'size' (bytes, formatted with size_format), 'version'
// (compared with version_compare, arrow shows update/downgrade), or 'text' (any change
// is just flagged, since there's no natural "up" or "down" for e.g. a theme name).
function webcare_wp_status_trend( $current, $previous, $kind = 'number' ) {
    if ( null === $current || null === $previous ) {
        return '';
    }

    if ( 'text' === $kind || 'version' === $kind ) {
        if ( (string) $current === (string) $previous ) {
            return ' <span class="webcare-wp-status-trend is-flat" title="No change since the previous log">&#8212;</span>';
        }
        if ( 'version' === $kind && version_compare( (string) $current, (string) $previous, '>' ) ) {
            return ' <span class="webcare-wp-status-trend is-up" title="Updated since the previous log">&#9650;</span>';
        }
        if ( 'version' === $kind && version_compare( (string) $current, (string) $previous, '<' ) ) {
            return ' <span class="webcare-wp-status-trend is-down" title="Downgraded since the previous log">&#9660;</span>';
        }
        return ' <span class="webcare-wp-status-trend is-changed" title="Changed since the previous log">changed</span>';
    }

    // number or size — guard against pre-1.10 logs where size fields were formatted
    // strings ("612 MB") rather than raw bytes, which can't be diffed.
    if ( ! is_numeric( $current ) || ! is_numeric( $previous ) ) {
        return '';
    }

    $diff = (float) $current - (float) $previous;

    if ( 0.0 === $diff ) {
        return ' <span class="webcare-wp-status-trend is-flat" title="No change since the previous log">&#8212;</span>';
    }

    $diff_display = ( 'size' === $kind ) ? size_format( abs( $diff ) ) : number_format_i18n( abs( $diff ) );

    if ( $diff > 0 ) {
        return ' <span class="webcare-wp-status-trend is-up" title="Up from the previous log">&#9650; ' . esc_html( $diff_display ) . '</span>';
    }

    return ' <span class="webcare-wp-status-trend is-down" title="Down from the previous log">&#9660; ' . esc_html( $diff_display ) . '</span>';
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

// --- Firewall + update summary additions (2026-08) ---

if ( ! defined( 'WEBCARE_WP_STATUS_WINDOW_DAYS' ) ) {
    define( 'WEBCARE_WP_STATUS_WINDOW_DAYS', 30 );
}

// NinjaFirewall stores its logs as flat files under wp-content/nfwlog/firewall_YYYY-MM.php,
// one per calendar month. These files persist on disk even while the plugin is deactivated,
// so this reads them directly rather than relying on the plugin being active. Each line is
// pipe-delimited; the 2nd bracketed field is the severity level. Values are NinjaFirewall's
// own constants (NFWLOG_MEDIUM = 1, NFWLOG_HIGH = 2, NFWLOG_CRITICAL = 3), hardcoded here
// (rather than read via defined()) so this still works while the plugin is deactivated and
// its constants aren't loaded.
function webcare_wp_status_get_ninjafirewall_summary( $days = null ) {
    $days    = $days ?: WEBCARE_WP_STATUS_WINDOW_DAYS;
    $log_dir = trailingslashit( WP_CONTENT_DIR ) . 'nfwlog/';

    if ( ! is_dir( $log_dir ) ) {
        return array( 'installed' => false );
    }

    $cutoff   = time() - ( $days * DAY_IN_SECONDS );
    $critical = 0;
    $medium   = 0;

    $months = array_unique( array( date( 'Y-m' ), date( 'Y-m', strtotime( '-1 month' ) ) ) );

    foreach ( $months as $month ) {
        $file = $log_dir . 'firewall_' . $month . '.php';
        if ( ! is_file( $file ) ) {
            continue;
        }
        $handle = @fopen( $file, 'r' );
        if ( ! $handle ) {
            continue;
        }
        while ( ( $line = fgets( $handle ) ) !== false ) {
            // Line format: [ts] [proc_time] [host] [#rule_id] [0] [level] [ip] [status] ...
            // The level is the 6th bracketed field, not the 2nd (that's the float
            // processing time) — skip the four fields in between to reach it.
            if ( ! preg_match( '/^\[(\d+)\]\s*\[[^\]]*\]\s*\[[^\]]*\]\s*\[[^\]]*\]\s*\[[^\]]*\]\s*\[(\d)\]/', $line, $m ) ) {
                continue;
            }
            $ts    = (int) $m[1];
            $level = (int) $m[2];
            if ( $ts < $cutoff ) {
                continue;
            }
            if ( 3 === $level ) {
                $critical++;
            } elseif ( 1 === $level ) {
                $medium++;
            }
        }
        fclose( $handle );
    }

    return array(
        'installed'   => true,
        'active'      => function_exists( 'is_plugin_active' ) && is_plugin_active( 'ninjafirewall/ninjafirewall.php' ),
        'critical'    => $critical,
        'medium'      => $medium,
        'window_days' => $days,
    );
}

// Defender (WPMU DEV) logs blocked/lockout events to a real DB table rather than files.
// "Blocked" here is every row in wp_defender_lockout_log within the window — this includes
// 404 scans, failed logins, and plugin-file-request attempts, not only entries that actually
// triggered a lockout. Narrow this to specific `type` values (e.g. only '404_lockout' and
// 'auth_lock') if a stricter definition of "blocked" is wanted later.
function webcare_wp_status_get_defender_summary( $days = null ) {
    global $wpdb;
    $days  = $days ?: WEBCARE_WP_STATUS_WINDOW_DAYS;
    $table = $wpdb->prefix . 'defender_lockout_log';

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        return array( 'installed' => false );
    }

    $cutoff = time() - ( $days * DAY_IN_SECONDS );
    $count  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `$table` WHERE date >= %d",
        $cutoff
    ) );

    return array(
        'installed'   => true,
        'active'      => function_exists( 'is_plugin_active' ) && is_plugin_active( 'defender-security/wp-defender.php' ),
        'blocked'     => $count,
        'window_days' => $days,
    );
}

// Outdated plugin count, from WordPress's own update-check data — no new tracking needed.
// Reads the cached site transient WP already populates on its normal update-check schedule,
// rather than forcing a fresh wordpress.org API call on every snapshot.
function webcare_wp_status_get_outdated_plugins_summary() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $update_data   = get_site_transient( 'update_plugins' );
    $installed     = get_plugins();
    $outdated      = array();

    if ( $update_data && ! empty( $update_data->response ) ) {
        foreach ( $update_data->response as $plugin_file => $info ) {
            // The update transient's objects don't carry a friendly "Name" field (just
            // slug/plugin/version/url/package) — look the display name up from the
            // regular installed-plugins list instead, falling back to the file path
            // only if that lookup somehow comes up empty.
            $outdated[] = isset( $installed[ $plugin_file ]['Name'] ) && $installed[ $plugin_file ]['Name']
                ? $installed[ $plugin_file ]['Name']
                : $plugin_file;
        }
    }

    return array(
        'outdated_count' => count( $outdated ),
        'outdated_names' => $outdated,
    );
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
            'ninjafirewall' => webcare_wp_status_get_ninjafirewall_summary(),
            'defender' => webcare_wp_status_get_defender_summary(),
            'updates' => webcare_wp_status_get_outdated_plugins_summary(),
        );


        $json_data = json_encode($data);

        // Create log folder if not exists
        $log_dir = webcare_wp_status_log_dir();
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
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

    $log_dir       = webcare_wp_status_log_dir();
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

// A random 64-char hex secret, used to authenticate remote requests to the REST endpoint below.
function webcare_wp_status_generate_api_key() {
    return bin2hex( random_bytes( 32 ) );
}

// Register the REST endpoint another site (the "puller") can call to pull this site's
// latest logs. Deliberately has no capability check — it's meant to be called machine-to-
// machine with no logged-in WP user involved. The API key in the request header IS the auth.
add_action( 'rest_api_init', 'webcare_wp_status_register_rest_routes' );
function webcare_wp_status_register_rest_routes() {
    register_rest_route( 'webcare-wp-status/v1', '/logs', array(
        'methods'             => 'GET',
        'callback'            => 'webcare_wp_status_rest_get_logs',
        'permission_callback' => 'webcare_wp_status_rest_check_key',
    ) );
}

function webcare_wp_status_rest_check_key( $request ) {
    $stored   = get_option( 'webcare_wp_status_api_key' );
    $provided = $request->get_header( 'x-webcare-key' );

    if ( ! $stored || ! $provided || ! hash_equals( $stored, $provided ) ) {
        return new WP_Error(
            'webcare_wp_status_unauthorized',
            __( 'Missing or invalid API key.', 'wp-status' ),
            array( 'status' => 401 )
        );
    }

    return true;
}

// Returns this site's 3 most recent logs. The limit is enforced here, server-side —
// not something a caller can raise by passing a bigger number.
function webcare_wp_status_rest_get_logs( $request ) {
    $log_dir   = webcare_wp_status_log_dir();
    $site_host = sanitize_file_name( preg_replace( '#^https?://#', '', site_url() ) );

    $files = (array) glob( $log_dir . $site_host . '-*.json' );
    usort( $files, function( $a, $b ) {
        return filemtime( $b ) - filemtime( $a );
    } );
    $files = array_slice( $files, 0, 3 );

    $logs = array();
    foreach ( $files as $file ) {
        $data = json_decode( file_get_contents( $file ), true );
        if ( $data ) {
            $logs[] = $data;
        }
    }

    return new WP_REST_Response( array(
        'site'  => site_url(),
        'count' => count( $logs ),
        'logs'  => $logs,
    ), 200 );
}

// Delete this site's log files older than $days. Returns how many were removed.
function webcare_wp_status_purge_old_logs( $days ) {
    $days = (int) $days;
    if ( $days <= 0 ) {
        return 0;
    }

    $log_dir   = webcare_wp_status_log_dir();
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
    add_option( 'webcare_wp_status_api_key', webcare_wp_status_generate_api_key() ); // Only sets it if it doesn't already exist

    $log_dir = webcare_wp_status_log_dir();
    if ( ! is_dir( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
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

// Register the "WebCare WP Status" widget on the main WP Dashboard (index.php)
add_action( 'wp_dashboard_setup', 'webcare_wp_status_register_dashboard_widget' );
function webcare_wp_status_register_dashboard_widget() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wp_add_dashboard_widget(
        'webcare_wp_status_dashboard_widget',
        'WebCare WP Status',
        'webcare_wp_status_render_dashboard_widget'
    );
}

// Build one trend row: current value, formatted, with an up/down arrow versus the previous log
function webcare_wp_status_widget_row( $label, $current, $previous, $is_size = false ) {
    $format = function( $n ) use ( $is_size ) {
        return $is_size ? size_format( $n ) : number_format_i18n( $n );
    };

    $current_display = ( null === $current ) ? '&#8212;' : esc_html( $format( $current ) );

    $trend = '<span class="webcare-wp-status-widget-trend is-flat">&#8212;</span>';

    if ( null !== $current && null !== $previous ) {
        $diff = $current - $previous;

        if ( $diff > 0 ) {
            $trend = '<span class="webcare-wp-status-widget-trend is-up" title="Up from last log">&#9650; ' . esc_html( $format( $diff ) ) . '</span>';
        } elseif ( $diff < 0 ) {
            $trend = '<span class="webcare-wp-status-widget-trend is-down" title="Down from last log">&#9660; ' . esc_html( $format( abs( $diff ) ) ) . '</span>';
        } else {
            $trend = '<span class="webcare-wp-status-widget-trend is-flat">No change</span>';
        }
    }

    return '<li class="webcare-wp-status-widget-row">'
        . '<span class="webcare-wp-status-widget-label">' . esc_html( $label ) . '</span>'
        . '<span class="webcare-wp-status-widget-value">' . $current_display . '</span>'
        . $trend
        . '</li>';
}

function webcare_wp_status_render_dashboard_widget() {
    $log_dir   = webcare_wp_status_log_dir();
    $site_host = sanitize_file_name( preg_replace( '#^https?://#', '', site_url() ) );
    $tools_url = admin_url( 'tools.php?page=wp_status' );

    $log_files = (array) glob( $log_dir . $site_host . '-*.json' );
    usort( $log_files, function( $a, $b ) {
        return filemtime( $b ) - filemtime( $a );
    } );

    ?>
    <style>
        .webcare-wp-status-widget-list { margin: 0 0 10px; padding: 0; list-style: none; }
        .webcare-wp-status-widget-row { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
        .webcare-wp-status-widget-row:last-child { border-bottom: none; }
        .webcare-wp-status-widget-label { flex: 1 1 auto; color: #1d2327; }
        .webcare-wp-status-widget-value { font-weight: 600; }
        .webcare-wp-status-widget-trend { min-width: 84px; text-align: right; font-size: 12px; }
        .webcare-wp-status-widget-trend.is-up { color: #2271b1; }
        .webcare-wp-status-widget-trend.is-down { color: #646970; }
        .webcare-wp-status-widget-trend.is-flat { color: #a7aaad; }
        .webcare-wp-status-widget-meta { margin: 0 0 8px; color: #646970; font-size: 12px; }
        .webcare-wp-status-widget-link { margin: 0; }
    </style>
    <?php

    if ( ! $log_files ) {
        echo '<p>' . esc_html__( 'No logs yet for this site.', 'wp-status' ) . '</p>';
        echo '<p class="webcare-wp-status-widget-link"><a href="' . esc_url( $tools_url ) . '">' . esc_html__( 'Create your first log →', 'wp-status' ) . '</a></p>';
        return;
    }

    $latest = json_decode( file_get_contents( $log_files[0] ), true );

    if ( ! $latest ) {
        echo '<p>' . esc_html__( 'Could not read the latest log.', 'wp-status' ) . '</p>';
        return;
    }

    $previous = isset( $log_files[1] ) ? json_decode( file_get_contents( $log_files[1] ), true ) : null;

    $current_content  = (int) $latest['pages_count'] + (int) $latest['posts_count'] + (int) $latest['published_custom_posts'];
    $current_plugins  = (int) $latest['plugins_count'];
    $current_size     = webcare_wp_status_total_bytes( $latest );

    $previous_content = $previous ? (int) $previous['pages_count'] + (int) $previous['posts_count'] + (int) $previous['published_custom_posts'] : null;
    $previous_plugins = $previous ? (int) $previous['plugins_count'] : null;
    $previous_size    = $previous ? webcare_wp_status_total_bytes( $previous ) : null;

    echo '<ul class="webcare-wp-status-widget-list">';
    echo webcare_wp_status_widget_row( __( 'Pages, Posts & CPTs', 'wp-status' ), $current_content, $previous_content );
    echo webcare_wp_status_widget_row( __( 'Plugins Installed', 'wp-status' ), $current_plugins, $previous_plugins );
    echo webcare_wp_status_widget_row( __( 'Total Site Size', 'wp-status' ), $current_size, $previous_size, true );
    echo '</ul>';

    if ( $previous ) {
        echo '<p class="webcare-wp-status-widget-meta">' . esc_html( sprintf(
            /* translators: 1: latest log date, 2: previous log date */
            __( 'Comparing %1$s to %2$s', 'wp-status' ),
            mysql2date( 'd M Y', $latest['date'] ),
            mysql2date( 'd M Y', $previous['date'] )
        ) ) . '</p>';
    } else {
        echo '<p class="webcare-wp-status-widget-meta">' . esc_html__( 'Generate one more log to see trends.', 'wp-status' ) . '</p>';
    }

    echo '<p class="webcare-wp-status-widget-link"><a href="' . esc_url( $tools_url ) . '">' . esc_html__( 'View full report →', 'wp-status' ) . '</a></p>';
}
