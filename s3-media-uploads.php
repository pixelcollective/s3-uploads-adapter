<?php

/*
Plugin Name: S3 Uploads Adapter
Description: Based on HM's S3 Uploads plugin
Author: Tiny Pixel Collective
Version: 1.1.0
Author URI: https://tinypixel.dev
License: MIT
*/

namespace TinyPixel;

require __DIR__ . '/vendor/autoload.php';

add_action('plugins_loaded', function () {
    (\TinyPixel\Uploads\Uploads::getInstance())->setup();
});
