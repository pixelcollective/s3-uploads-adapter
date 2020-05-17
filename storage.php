<?php

/*
Plugin Name: S3 Uploads Adapter
Description: Based on HM's S3 Uploads plugin
Author: Tiny Pixel Collective
Version: 2.0.0
Author URI: https://tinypixel.dev
License: MIT
*/

namespace TinyPixel\Storage;

$plugin = require __DIR__ . '/src/bootstrap.php';

add_action('plugins_loaded', function () use ($plugin) {
    $plugin->get('plugin')->applyFilters($plugin);
});

if (defined('WP_CLI') && $cli = $plugin->get('wp.cli')) {
    $cli::add_command('storage', $plugin->get('plugin.cli'));
    $cli::add_command('storage disk s3', $plugin->get('plugin.cli.s3'));
    $cli::add_command('storage disk local', $plugin->get('plugin.cli.local'));
}
