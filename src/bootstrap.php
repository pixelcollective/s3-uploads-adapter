<?php

/**
 * The bootstrap file creates and returns the container.
 */

namespace TinyPixel\Storage;

if (! $autoload = realpath(__DIR__ . '/../vendor/autoload.php')) {
    throw new \WP_Error('autoload_not_found');
}

require __DIR__ . '/../vendor/autoload.php';

use \WP_CLI;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Aws\S3\S3Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\MountManager;
use League\Flysystem\Filesystem;
use TinyPixel\Storage\Plugin;
use TinyPixel\Storage\CLI;
use TinyPixel\Storage\ImageEditor;

/**
 * Build the plugin container.
 */

$builder = new ContainerBuilder;

$builder->useAutowiring(false);
$builder->useAnnotations(false);

$builder->addDefinitions([
    /**
     * Plugin resolvers
     */
    'plugin' => function (ContainerInterface $plugin) {
        return new Plugin\Runtime($plugin);
    },
    'plugin.editor' => function (ContainerInterface $plugin) {
        return new ImageEditor\Editor($plugin);
    },

    /**
     * Plugin CLI resolvers
     */
    'plugin.cli' => function (ContainerInterface $plugin) {
        return new CLI\Storage($plugin);
    },
    'plugin.cli.local' => function (ContainerInterface $plugin) {
        return new CLI\Local($plugin);
    },
    'plugin.cli.s3' => function (ContainerInterface $plugin) {
        return new CLI\S3($plugin);
    },

    /**
     * Illuminate
     */
    'collection' => Collection::class,
    'str' => Str::class,

    /**
     * Flysystem
     */
    'storage' => function (ContainerInterface $plugin) {
        return new MountManager([
            'local' => new Filesystem($plugin->get('storage.filesystem.local')),
            's3' => $plugin->get('storage.filesystem.s3'),
        ]);
    },
    'storage.filesystem.local' => function (ContainerInterface $plugin) {
        return new Local($plugin->get('plugin.fs.content'));
    },
    'storage.filesystem.s3' => function (ContainerInterface $plugin) {
        return new Filesystem($plugin->get('storage.s3.adapter'));
    },
    'storage.s3.adapter' => function (ContainerInterface $plugin) {
        return new AwsS3Adapter(
            $plugin->get('storage.s3.client'),
            $plugin->get('storage.s3.config')->bucket,
            'app'
        );
    },
    'storage.s3.client' => function (ContainerInterface $plugin) {
        return new S3Client([
            'version' => 'latest',
            'credentials' => [
                'key' => $plugin->get('storage.s3.config')->key,
                'secret' => $plugin->get('storage.s3.config')->secret,
            ],
            'region' => $plugin->get('storage.s3.config')->region,
            'endpoint' => $plugin->get('storage.s3.config')->endpoint,
        ]);
    },
    'storage.s3.config' => (object) [
        'region' => defined('S3_REGION') ? S3_REGION : null,
        'bucket' => defined('S3_BUCKET') ? S3_BUCKET : null,
        'key' => defined('S3_KEY') ? S3_KEY : null,
        'secret' => defined('S3_SECRET') ? S3_SECRET : null,
        'endpoint' => defined('S3_ENDPOINT') ? S3_ENDPOINT : 'https://nyc3.digitaloceanspaces.com',
        'signature' => defined('S3_SIGNATURE') ? S3_SIGNATURE : 'v4',
        'bucketPath' => defined('S3_BUCKET_PATH') ? S3_BUCKET_PATH : "s3://" . S3_BUCKET . "/app",
        'bucketUrl' => defined('S3_BUCKET_URL') ? S3_BUCKET_URL : 'https://' . join('.', [S3_BUCKET, S3_REGION, 'cdn.digitaloceanspaces.com']),
    ],

    /**
     * Configuration
     */
    'plugin.namespace' => 'tiny-pixel',
    'plugin.fs.upload' => (object) wp_upload_dir(),
    'plugin.fs.content' => WP_CONTENT_DIR,
    'plugin.fs.baseDir' => realpath(__DIR__ . '/../'),

    'wp.cli' => WP_CLI::class,
    'wp.includes.file' => function () {
        if (! function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
    },

    /**
     * Cached filters
     */
    'wp.filters' => function (ContainerInterface $plugin) {
        if (! $filters = wp_cache_get('filters', $plugin->get('plugin.namespace'))) {
            $filters = $plugin->get('collection')::make(
                json_decode(file_get_contents(
                    "{$plugin->get('plugin.fs.baseDir')}/vendor/johnbillion/wp-hooks/hooks/filters.json"
                ))
            );

            wp_cache_add('filters', $filters, $plugin->get('plugin.namespace'));
        }

        return $filters;
    },
]);

return $builder->build();
