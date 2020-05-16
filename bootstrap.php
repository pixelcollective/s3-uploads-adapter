<?php

/**
 * The bootstrap file creates and returns the container.
 */

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use TinyPixel\Uploads\Uploads;
use TinyPixel\Uploads\UploadsCLI;
use Illuminate\Support\Collection;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use League\Flysystem\MountManager;

$builder = new ContainerBuilder;

$builder->addDefinitions([
    's3' => S3Client::class,
    'flysystem.s3' => AwsS3Adapter::class,
    'flysystem.filesystem' => Filesystem::class,
    'flysystem.mounts' => MountManager::class,
    'flysystem.local' => Local::class,
    'collection' => Collection::class,
    'cli' => function (ContainerInterface $plugin) {
        return new UploadsCLI($plugin);
    },
    'uploads' => function (ContainerInterface $plugin) {
        return new Uploads($plugin);
    },

    'fs.app' => function (ContainerInterface $plugin) {
        return $plugin->make(
            'flysystem.local',
            $plugin->get('path.app')
        );
    },
    'fs.cloud' => function (ContainerInterface $plugin) {
        return $plugin->make('fysystem.s3', [
            $plugin->get('s3.config')->get('region'),
            $plugin->get('s3.config')->get('bucket'),
            $plugin->get('s3.config')->get('bucketPath'),
        ]);
    },
    'disks' => function (ContainerInterface $plugin) {
        return $plugin->make('flysystem.mounts', [
            'local' => $plugin->make('flysystem.filesystem', $plugin->get('fs.app')),
            's3' => $plugin->make('flysystem.filesystem', $plugin->get('fs.cloud')),
        ]);
    },
    's3.config' => function (ContainerInterface $plugin) {
        return $plugin->get('collection')::make([
            'region' => defined('S3_UPLOADS_REGION') ? S3_UPLOADS_REGION : null,
            'bucket' => defined('S3_UPLOADS_BUCKET') ? S3_UPLOADS_BUCKET : null,
            'key' => defined('S3_UPLOADS_KEY') ? S3_UPLOADS_KEY : null,
            'secret' => defined('S3_UPLOADS_SECRET') ? S3_UPLOADS_SECRET : null,
            'endpoint' => defined('S3_UPLOADS_ENDPOINT') ? S3_UPLOADS_ENDPOINT : null,
            'signature' => defined('S3_UPLOADS_SIGNATURE') ? S3_UPLOADS_SIGNATURE : 'v4',
            'bucketPath' => defined('S3_UPLOADS_BUCKET_PATH') ? S3_UPLOADS_BUCKET_PATH : "s3://" . S3_UPLOADS_BUCKET . "/app",
            'bucketUrl' => defined('S3_UPLOADS_BUCKET_URL') ? S3_UPLOADS_BUCKET_URL : "https://" . S3_UPLOADS_BUCKET . ".cdn.digitaloceanspaces.com",
            'editor' => '\\TinyPixel\\Uploads\\ImageEditorImagick',
        ]);
    },
    's3.client' => function (ContainerInterface $plugin) {
        return $plugin->make('s3', [
            'version' => 'latest',
            'credentials' => [
                'key' => $plugin->get('s3.config')->get('key'),
                'secret' => $plugin->get('s3.config')->get('secret'),
            ],
            'region' => $plugin->get('s3.config')->get('region'),
            'endpoint' => $plugin->get('s3.config')->get('endpoint'),
        ]);
    },
    'plugin.namespace' => 'tiny-pixel',
    'plugin.url' => plugins_url('', __DIR__),
    'path.app' => WP_CONTENT_DIR,
    'path.plugin' => realpath(__DIR__ . '/../'),
]);

return $builder->build();
