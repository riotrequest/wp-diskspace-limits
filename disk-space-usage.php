<?php
/*
Plugin Name: Disk Space Usage with Limit and Restrictions
Description: Displays, limits, and restricts the disk space used by the WordPress installation.
Version: 1.2
Author: riotrequest
*/

// Define the disk space limit (in MB)
define('DISK_SPACE_LIMIT', 700);

// Function to calculate disk space usage
function calculate_disk_space_usage() {
    $root_path = ABSPATH; // ABSPATH is the WordPress root directory
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

    return round($total_size / 1024 / 1024, 2); // size in MB
}

// Function to get disk space usage with caching
function get_disk_space_usage() {
    $disk_usage = get_transient('disk_space_usage');
    if ($disk_usage === false) {
        $disk_usage = calculate_disk_space_usage();
        set_transient('disk_space_usage', $disk_usage, 3600); // Cache for 1 hour
    }
    return $disk_usage;
}

// Function to clear disk space usage transient
function clear_disk_space_usage_transient() {
    delete_transient('disk_space_usage');
}

// Hook into the WordPress dashboard setup
add_action('wp_dashboard_setup', 'add_disk_space_dashboard_widget');

// Function to add the widget to the dashboard
function add_disk_space_dashboard_widget() {
    wp_add_dashboard_widget(
        'disk_space_usage_widget', 
        'Disk Space Usage with Limit', 
        'display_disk_space_usage_with_limit'
    );
}

// Function to display the disk space usage and limit
function display_disk_space_usage_with_limit() {
    $disk_usage = get_disk_space_usage();
    $disk_limit = DISK_SPACE_LIMIT;

    echo "<p>Total Disk Space Used: <strong>{$disk_usage} MB</strong></p>";
    echo "<p>Disk Space Limit: <strong>{$disk_limit} MB</strong></p>";

    if ($disk_usage > $disk_limit) {
        echo "<p><strong>Warning:</strong> Disk space limit exceeded!</p>";
    }
}

// Function to check if the disk space limit has been reached
function is_disk_space_limit_reached() {
    $current_usage = get_disk_space_usage();
    return $current_usage >= DISK_SPACE_LIMIT;
}

// Hook to intercept file uploads
add_filter('wp_handle_upload_prefilter', 'prevent_upload_if_limit_reached');
function prevent_upload_if_limit_reached($file) {
    if (is_disk_space_limit_reached()) {
        $file['error'] = 'Disk space limit reached. Cannot upload new files.';
    } else {
        clear_disk_space_usage_transient();
    }
    return $file;
}

// Hook to intercept plugin installation
add_filter('upgrader_pre_install', 'prevent_plugin_install_if_limit_reached', 10, 2);
function prevent_plugin_install_if_limit_reached($true, $hook_extra) {
    if (isset($hook_extra['plugin']) && is_disk_space_limit_reached()) {
        return new WP_Error('disk_space_error', 'Disk space limit reached. Cannot install new plugins.');
    }
    clear_disk_space_usage_transient();
    return $true;
}

// Hook to intercept post creation
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

// Function to add a notice query var
function add_notice_query_var($location) {
    remove_filter('redirect_post_location', 'add_notice_query_var', 99);
    return add_query_arg(array('disk_space_error' => 1), $location);
}

// Hook to display admin notice
add_action('admin_notices', 'disk_space_limit_admin_notice');
function disk_space_limit_admin_notice() {
    if (!empty($_GET['disk_space_error'])) {
        echo '<div class="notice notice-error"><p>Disk space limit reached. Post saved as a draft.</p></div>';
    }
}

// Hooks to clear transient on various actions
add_action('delete_attachment', 'clear_disk_space_usage_transient');
add_action('add_attachment', 'clear_disk_space_usage_transient');
add_action('wp_delete_file', 'clear_disk_space_usage_transient');
add_action('upgrader_process_complete', 'clear_disk_space_usage_transient', 10, 2);
add_action('deleted_plugin', 'clear_disk_space_usage_transient');
add_action('activated_plugin', 'clear_disk_space_usage_transient');
add_action('deactivated_plugin', 'clear_disk_space_usage_transient');
