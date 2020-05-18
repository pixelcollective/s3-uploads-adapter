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
use TinyPixel\Storage\ImageEditor\ImagickEditor;

/**
 * Build the plugin container.
 */
$builder = new ContainerBuilder;

/** Minimal config */
$builder->useAutowiring(false);
$builder->useAnnotations(false);

/**
 * Container definitions
 */
$builder->addDefinitions([
    /**
     * Plugin resolvers
     */
    'plugin' => function (ContainerInterface $plugin) {
        return new Plugin\Runtime($plugin);
    },
    'plugin.imagickEditor' => function (ContainerInterface $plugin) {
        return new ImagickEditor($plugin);
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
     * Illuminate support components
     */
    'collection' => Collection::class,
    'str' => Str::class,
    /**
     * Flysystem
     */
    'disks' => function (ContainerInterface $plugin) {
        return new MountManager([
            'local' => new Filesystem($plugin->get('disk.local')),
            's3' => $plugin->get('disk.s3'),
        ]);
    },
    'disk.local' => new Local(WP_CONTENT_DIR),
    'disk.s3' => function (ContainerInterface $plugin) {
        return new Filesystem($plugin->get('disk.s3.adapter'));
    },
    'disk.s3.adapter' => function (ContainerInterface $plugin) {
        return new AwsS3Adapter(
            $plugin->get('disk.s3.client'),
            $plugin->get('disk.s3.config')->bucket,
            'app'
        );
    },
    'disk.s3.client' => function (ContainerInterface $plugin) {
        return new S3Client([
            'version' => 'latest',
            'credentials' => [
                'key' => $plugin->get('disk.s3.config')->key,
                'secret' => $plugin->get('disk.s3.config')->secret,
            ],
            'region' => $plugin->get('disk.s3.config')->region,
            'endpoint' => $plugin->get('disk.s3.config')->endpoint,
        ]);
    },
    'disk.s3.config' => (object) [
        'region' => defined('S3_REGION') ? S3_REGION : null,
        'bucket' => defined('S3_BUCKET') ? S3_BUCKET : null,
        'key' => defined('S3_KEY') ? S3_KEY : null,
        'secret' => defined('S3_SECRET') ? S3_SECRET : null,
        'endpoint' => defined('S3_ENDPOINT') ? S3_ENDPOINT : 'https://nyc3.digitaloceanspaces.com',
        'signature' => defined('S3_SIGNATURE') ? S3_SIGNATURE : 'v4',
        'bucketPath' => defined('S3_BUCKET_PATH') ? S3_BUCKET_PATH : "s3://" . S3_BUCKET . "/app",
        'bucketUrl' => defined('S3_BUCKET_URL') ? S3_BUCKET_URL : 'https://' . join('.', [S3_BUCKET, S3_REGION, 'cdn.digitaloceanspaces.com']),
    ],
    'fs' => function (ContainerInterface $plugin) {
        $wp = (object) wp_upload_dir();

        $local = (object) $plugin->get('collection')::make($wp)
            ->put('path', str_replace(WP_CONTENT_DIR, "local:/", $wp->path))
            ->put('basedir', str_replace(WP_CONTENT_DIR, 'local:/', $wp->basedir))
            ->toArray();

        $s3 = (object) $plugin->get('collection')::make($wp)
            ->put('path', str_replace(
                'local://',
                's3://' . $plugin->get('disk.s3.config')->bucket . '/',
                $local->path
            ))
            ->put('basedir', str_replace(
                'local://',
                $plugin->get('disk.s3.config')->bucketPath . '/',
                $local->basedir
            ))
            ->put('url', str_replace(
                home_url(),
                $plugin->get('disk.s3.config')->bucketUrl,
                $local->url
            ))
            ->put('baseurl', str_replace(
                home_url(),
                $plugin->get('disk.s3.config')->bucketUrl,
                $local->baseurl
            ))
            ->toArray();

        return (object) [
            'wp' => $wp,
            's3' => $s3,
            'local' => $local
        ];
    },
    /**
     * Configuration
     */
    'plugin.baseDir' => realpath(__DIR__ . '/../'),
    'plugin.namespace' => 'tiny-pixel',
    /**
     * WP bindings
     */
    'wp.cli' => WP_CLI::class,
    'wp.includes.file' => function () {
        if (! function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
    },
]);

return $builder->build();
