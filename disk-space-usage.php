<?php
/*
Plugin Name: Disk Space Usage with Limit and Restrictions
Description: Displays, limits, and restricts the disk space used by the WordPress installation. Additionally, it blocks plugin and theme installations when the disk space limit is reached and recommends removing default themes to save space.
Version: 1.3
Author: riotrequest (Updated by WP Manager)
*/

// Hard-coded disk space limit (in MB)
define('DISK_SPACE_LIMIT', 700);
// Estimated savings (in MB) if the default theme (Twenty Twenty) is removed
define('DEFAULT_THEME_SAVINGS_MB', 50);

// Calculate disk space usage by recursively iterating through all files in ABSPATH
function calculate_disk_space_usage() {
    $root_path = ABSPATH;
    $total_size = 0;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root_path),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isFile()) {
            $total_size += $file->getSize();
        }
    }

    return round($total_size / 1024 / 1024, 2); // Return size in MB
}

// Cache disk usage to reduce performance impact; cached for 1 hour
function get_disk_space_usage() {
    $disk_usage = get_transient('disk_space_usage');
    if ($disk_usage === false) {
        $disk_usage = calculate_disk_space_usage();
        set_transient('disk_space_usage', $disk_usage, 3600);
    }
    return $disk_usage;
}

// Clear the transient cache so recalculations occur after file changes
function clear_disk_space_usage_transient() {
    delete_transient('disk_space_usage');
}

// Add a dashboard widget to display disk usage information
add_action('wp_dashboard_setup', 'add_disk_space_dashboard_widget');
function add_disk_space_dashboard_widget() {
    wp_add_dashboard_widget(
        'disk_space_usage_widget',
        'Disk Space Usage with Limit',
        'display_disk_space_usage_with_limit'
    );
}

// Output disk usage and limit; warn if limit is exceeded
function display_disk_space_usage_with_limit() {
    $disk_usage = get_disk_space_usage();
    $disk_limit = DISK_SPACE_LIMIT;

    echo "<p>Total Disk Space Used: <strong>{$disk_usage} MB</strong></p>";
    echo "<p>Disk Space Limit: <strong>{$disk_limit} MB</strong></p>";

    if ($disk_usage > $disk_limit) {
        echo "<p><strong>Warning:</strong> Disk space limit exceeded!</p>";
    }
}

// Determine if the current disk usage has reached or exceeded the limit
function is_disk_space_limit_reached() {
    return (get_disk_space_usage() >= DISK_SPACE_LIMIT);
}

// Prevent file uploads if the disk space limit is reached
add_filter('wp_handle_upload_prefilter', 'prevent_upload_if_limit_reached');
function prevent_upload_if_limit_reached($file) {
    if (is_disk_space_limit_reached()) {
        $file['error'] = 'Disk space limit reached. Cannot upload new files.';
    } else {
        clear_disk_space_usage_transient();
    }
    return $file;
}

// Prevent installation of plugins or themes when disk space is full
add_filter('upgrader_pre_install', 'prevent_install_if_limit_reached', 10, 2);
function prevent_install_if_limit_reached($true, $hook_extra) {
    if ((isset($hook_extra['plugin']) || isset($hook_extra['theme'])) && is_disk_space_limit_reached()) {
        $type = isset($hook_extra['plugin']) ? 'plugin' : 'theme';
        return new WP_Error('disk_space_error', "Disk space limit reached. Cannot install new {$type}s.");
    }
    clear_disk_space_usage_transient();
    return $true;
}

// When publishing posts, if the limit is reached, force the post to be saved as a draft
add_filter('wp_insert_post_data', 'prevent_post_creation_if_limit_reached', 10, 2);
function prevent_post_creation_if_limit_reached($data, $postarr) {
    if (is_disk_space_limit_reached() && $data['post_status'] == 'publish') {
        $data['post_status'] = 'draft';
        add_filter('redirect_post_location', 'add_notice_query_var', 99);
    } else {
        clear_disk_space_usage_transient();
    }
    return $data;
}

// Add a query var to indicate that a disk space error occurred during post creation
function add_notice_query_var($location) {
    remove_filter('redirect_post_location', 'add_notice_query_var', 99);
    return add_query_arg(array('disk_space_error' => 1), $location);
}

// Display admin notices: one for disk space issues and one suggesting removal of default themes
add_action('admin_notices', 'disk_space_limit_admin_notice');
function disk_space_limit_admin_notice() {
    // Notice when actions are blocked due to disk space issues
    if (!empty($_GET['disk_space_error'])) {
        echo '<div class="notice notice-error"><p>Disk space limit reached. Post saved as a draft.</p></div>';
    }
    
    // Recommend removal of the default Twenty Twenty theme if it exists
    $default_theme_dir = WP_CONTENT_DIR . '/themes/twentytwenty';
    if (file_exists($default_theme_dir)) {
        echo '<div class="notice notice-warning"><p>It appears that the default theme (Twenty Twenty) is still installed. Removing it could free up approximately ' . DEFAULT_THEME_SAVINGS_MB . ' MB of disk space.</p></div>';
    }
}

// Clear disk space transient when various file operations occur
add_action('delete_attachment', 'clear_disk_space_usage_transient');
add_action('add_attachment', 'clear_disk_space_usage_transient');
add_action('wp_delete_file', 'clear_disk_space_usage_transient');
add_action('upgrader_process_complete', 'clear_disk_space_usage_transient', 10, 2);
add_action('deleted_plugin', 'clear_disk_space_usage_transient');
add_action('activated_plugin', 'clear_disk_space_usage_transient');
add_action('deactivated_plugin', 'clear_disk_space_usage_transient');
