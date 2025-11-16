<?php
/**
 * PixelFlow Uninstall Script
 *
 * This file is called automatically by WordPress when the plugin is deleted.
 * It checks if the user has enabled the "remove on uninstall" option and
 * removes all plugin data from the database if enabled.
 *
 * @package PixelFlow
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if user has opted to remove data on uninstall
$general_options = get_option('pixelflow_general_options', array());

// Only proceed with cleanup if the remove_on_uninstall option is enabled
if (isset($general_options['remove_on_uninstall']) && $general_options['remove_on_uninstall'] === 1) {
    // Delete all PixelFlow options from the database
    delete_option('pixelflow_general_options');
    delete_option('pixelflow_class_options');
    delete_option('pixelflow_debug_options');
    delete_option('pixelflow_code');
    delete_option('pixelflow_db_version');

    // For multisite installations, delete options from all sites
    if (is_multisite()) {
        // Get all site IDs using WordPress function
        $sites = get_sites(
            array(
                'number' => 0, // Get all sites
                'fields' => 'ids',
            )
        );

        foreach ($sites as $blog_id) {
            switch_to_blog($blog_id);

            // Delete options for this site
            delete_option('pixelflow_general_options');
            delete_option('pixelflow_class_options');
            delete_option('pixelflow_debug_options');
            delete_option('pixelflow_code');
            delete_option('pixelflow_db_version');

            restore_current_blog();
        }
    }
}


