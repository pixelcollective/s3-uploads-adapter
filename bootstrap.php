<?php

/**
 * The bootstrap file creates and returns the container.
 */

require __DIR__ . '/vendor/autoload.php';

use function DI\autowire;
use function DI\create;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Illuminate\Support\Collection;
use Aws\S3\S3Client;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\MountManager;
use League\Flysystem\Filesystem;

$builder = new ContainerBuilder;

$builder->addDefinitions([
    'cli' => autowire('\TinyPixel\Uploads\UploadsCLI'),
    'uploads' => autowire('\TinyPixel\Uploads\Uploads'),
    'collection' => Collection::class,
    'paths.upload' => (object) wp_upload_dir(),
    'paths.content' => WP_CONTENT_DIR,
    'paths.plugin' => realpath(__DIR__ . '/../'),
    'wp.includes.file' => function () {
        if (! function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
    },
    'storage' => function (ContainerInterface $plugin) {
        return new MountManager([
            'local' => new Filesystem($plugin->get('storage.adapters.local')),
            's3' => new Filesystem($plugin->get('storage.adapters.s3')),
        ]);
    },
    'storage.adapters.local' => function (ContainerInterface $plugin) {
        return new Local($plugin->get('paths.content'));
    },
    'storage.adapters.s3' => function (ContainerInterface $plugin) {
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
        'bucketUrl' => defined('S3_BUCKET_URL') ? S3_BUCKET_URL : "https://" . S3_BUCKET . ".cdn.digitaloceanspaces.com",
        'editor' => '\\TinyPixel\\Uploads\\ImageEditorImagick',
    ],
]);

return $builder->build();
