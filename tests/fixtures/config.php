<?php
/**
 * Service addresses for the test cases that need a built plugin's configuration, in the shape
 * build_plugin.sh writes to includes/config.php. A test opts in by defining
 * PIXELFLOW_CONFIG_FILE as this file's path.
 */

defined('ABSPATH') || exit;

return array(
    'api_url' => 'https://api.example.test',
    'ui_url'  => 'https://dashboard.example.test',
    'cdn_url' => 'https://cdn.example.test/pfm.js',
);
