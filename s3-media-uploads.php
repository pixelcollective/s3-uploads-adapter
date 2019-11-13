<?php
/*
Plugin Name: S3 Uploads Adapter
Description: Based on hm's S3 Uploads plugin
Author: Tiny Pixel Collective
Version: 1.0.0
Author URI: https://tinypixel.dev
License: MIT
*/
namespace TinyPixel;

require __DIR__ . '/vendor/autoload.php';

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('s3', '\\TinyPixel\\Uploads\\UploadsCLI');
}

add_action('plugins_loaded', function () {
    (\TinyPixel\Uploads\Uploads::getInstance())->setup();
});
