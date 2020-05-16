<?php

/*
Plugin Name: S3 Uploads Adapter
Description: Based on HM's S3 Uploads plugin
Author: Tiny Pixel Collective
Version: 2.0.0
Author URI: https://tinypixel.dev
License: MIT
*/

namespace TinyPixel;

$plugin = require __DIR__ . '/bootstrap.php';

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('s3', function() use ($plugin) {
        return $plugin->get('cli');
    });
}

add_action('plugins_loaded', function () use ($plugin) {
    $plugin->get('uploads')->setup();
});
