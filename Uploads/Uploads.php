<?php

namespace TinyPixel\Uploads;

use Aws\S3\S3Client;
use TinyPixel\Uploads\StreamWrapper;

/**
 * S3 Uploads
 *
 * @package TinyPixel\Uploads
 */
class Uploads
{
    /** @var TinyPixel\Uploads\Uploads */
    private static $instance;

    public $acl = 'public-read';
    public $local = false;
    public $region = null;
    public $version = 'latest';
    public $client;
    public $localUploadDir;
    public $bucket;
    public $key;
    public $secret;
    public $endpoint;
    public $signature;
    public $bucketPath;
    public $bucketUrl;
    public $editor;

    /**
     * Singleton constructor.
     *
     * @return \TinyPixel\Uploads\Uploads
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new Uploads();
        }

        return self::$instance;
    }

    /**
     * Class constructor.
     */
    public function __construct()
    {
        if (defined('S3_UPLOADS_OBJECT_ACL')) $this->acl = S3_UPLOADS_OBJECT_ACL;
        if (defined('S3_UPLOADS_USE_LOCAL')) $this->local = S3_UPLOADS_USE_LOCAL;
        if (defined('S3_UPLOADS_REGION')) $this->region = S3_UPLOADS_REGION;

        $this->region = defined('S3_UPLOADS_REGION') ? S3_UPLOADS_REGION : null;
        $this->bucket = defined('S3_UPLOADS_BUCKET') ? S3_UPLOADS_BUCKET : null;
        $this->key = defined('S3_UPLOADS_KEY') ? S3_UPLOADS_KEY : null;
        $this->secret = defined('S3_UPLOADS_SECRET') ? S3_UPLOADS_SECRET : null;
        $this->endpoint = defined('S3_UPLOADS_ENDPOINT') ? S3_UPLOADS_ENDPOINT : null;
        $this->signature = defined('S3_UPLOADS_SIGNATURE') ? S3_UPLOADS_SIGNATURE : 'v4';
        $this->bucketPath = defined('S3_UPLOADS_BUCKET_PATH') ? S3_UPLOADS_BUCKET_PATH : "s3://{$this->bucket}/app";
        $this->bucketUrl = defined('S3_UPLOADS_BUCKET_URL') ? S3_UPLOADS_BUCKET_URL : "https://{$this->bucket}.{$this->region}.cdn.digitaloceanspaces.com";
        $this->editor = '\\TinyPixel\\Uploads\\ImageEditorImagick';
    }

    /**
     * Setup the hooks, urls filtering etc for S3 Uploads
     *
     * @return void
     */
    public function setup(): void
    {
        $this->filterParameters();
        $this->configureClient();
        $this->registerStreamWrapper();

        add_filter('upload_dir', [$this, 'filterUploadDir']);
        add_filter('wp_image_editors', [$this, 'filterEditors'], 9);
        add_filter('wp_read_image_metadata', [$this, 'filterMetadata'], 10, 2);
        add_filter('wp_resource_hints', [$this, 'filterResourceHints'], 10, 2);
        add_action('delete_attachment', [$this, 'deleteAttachment']);
        add_action('wp_handle_sideload_prefilter', [$this, 'sideload']);

        remove_filter('admin_notices', 'wpthumb_errors');
    }

    /**
     * Filter parameters
     *
     * @return void
     */
    public function filterParameters(): void
    {
        $this->acl = apply_filters('s3_media_acl', $this->acl);
        $this->local = apply_filters('s3_media_local', $this->local);
        $this->region = apply_filters('s3_media_region', $this->region);
        $this->bucket = apply_filters('s3_media_bucket', $this->bucket);
        $this->key = apply_filters('s3_media_key', $this->key);
        $this->secret = apply_filters('s3_media_secret', $this->secret);
        $this->endpoint = apply_filters('s3_media_endpoint', $this->endpoint);
        $this->signature  = apply_filters('s3_media_signature', $this->signature);
        $this->bucketPath = apply_filters('s3_media_bucket_path', $this->bucketPath);
        $this->bucketUrl  = apply_filters('s3_media_bucket_url', $this->bucketUrl);
        $this->editor  = apply_filters('s3_media_editor', $this->editor);
    }

    /**
     * Register the stream wrapper for s3
     *
     * @return void
     */
    public function registerStreamWrapper(): void
    {
        if ($this->local) {
            stream_wrapper_register('s3', '\\TinyPixel\\Uploads\\LocalStreamWrapper', STREAM_IS_URL);
        } else {
            StreamWrapper::register($this->getClient());

            stream_context_set_option(stream_context_get_default(), 's3', 'ACL', $this->acl);
        }

        stream_context_set_option(stream_context_get_default(), 's3', 'seekable', true);
    }

    /**
     * Configure S3 client
     *
     * @return void
     */
    public function configureClient(): void
    {
        $clientParams = apply_filters('s3_uploads_s3_client_params', [
            'version' => $this->version,
            'signature' => $this->signature,
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'csm' => false,
            'use_arn_region' => false,
            'credentials' => [
                'key' => $this->key,
                'secret' => $this->secret,
            ],
        ]);

        $this->client = new S3Client($clientParams);
    }

    /**
     * Get S3 client instance
     *
     * @return Aws\S3\S3Client
     */
    public function getClient(): \Aws\S3\S3Client
    {
        if ($this->client) {
            return $this->client;
        } else {
            throw new \Exception('\\AWS\\S3\\S3Client not available.');
        }
    }

    /**
     * Filter uploads dir.
     *
     * @param  array dirs
     * @return array
     */
    public function filterUploadDir(array $dirs): array
    {
        $this->originalUploadDir = $dirs;

        $dirs['path']    = str_replace(WP_CONTENT_DIR, $this->bucketPath, $dirs['path']);
        $dirs['basedir'] = str_replace(WP_CONTENT_DIR, $this->bucketPath, $dirs['basedir']);

        $dirs['url']     = str_replace("s3://{$this->bucket}", $this->bucketUrl, $dirs['path']);
        $dirs['baseurl'] = str_replace("s3://{$this->bucket}", $this->bucketUrl, $dirs['basedir']);

        return apply_filters('sup_uploads_dirs', $dirs);
    }

    /**
     * Delete all attachment files from S3 when an attachment is deleted.
     *
     * WordPress Core's handling of deleting files for attachments via
     * wp_deleteAttachment is not compatible with remote streams, as
     * it makes many assumptions about local file paths. The hooks also do
     * not exist to be able to modify their behavior. As such, we just clean
     * up the s3 files when an attachment is removed, and leave WordPress to try
     * a failed attempt at mangling the s3:// urls.
     *
     * @param  int postId
     * @return void
     */
    public function deleteAttachment(int $postId): void
    {
        $meta = wp_get_attachment_metadata($postId);
        $file = get_attached_file($postId);

        if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeinfo) {
                $intermediate = str_replace(basename($file), $sizeinfo['file'], $file);

                unlink($intermediate);
            }
        }

        unlink($file);
    }

    /**
     * Filter wordpress editors.
     *
     * @param  array editors
     * @return array editors
     */
    public function filterEditors($editors)
    {
        if (($position = array_search('WP_Image_Editor_Imagick', $editors)) !== false) {
            unset($editors[$position]);
        }

        array_unshift($editors, $this->editor);

        return $editors;
    }

    /**
     * Copy the file from /tmp to an s3 dir so handle_sideload doesn't fail due to
     * trying to do a rename() on the file cross streams. This is somewhat of a hack
     * to work around the core issue https://core.trac.wordpress.org/ticket/29257
     *
     * @param  array File array
     * @return array
     */
    public function sideload(array $file): array
    {
        $uploadDir = wp_upload_dir();

        $filename  = basename($file['tmp_name']);
        $finalPath = "{$uploadDir['basedir']}/tmp/{$filename}";

        copy($file['tmp_name'], $finalPath);
        unlink($file['tmp_name']);

        $file['tmp_name'] = $finalPath;

        return $file;
    }

    /**
     * Filters wp_read_image_metadata. exif_read_data() doesn't work on
     * file streams so we need to make a temporary local copy to extract
     * exif data from.
     *
     * @param array  $meta
     * @param string $file
     * @return array|bool
     */
    public function filterMetadata($meta, $file)
    {
        remove_filter('wp_read_image_metadata', [$this, 'filterMetadata'], 10);

        if ($temp = $this->copyImageFromS3($file)) {
            $meta = wp_read_image_metadata($temp);

            add_filter('wp_read_image_metadata', [$this, 'filterMetadata'], 10, 2);

            unlink($temp);
        }

        return $meta;
    }

    /**
     * Add the DNS address for the S3 Bucket to list for DNS prefetch.
     *
     * @param $hints
     * @param $relation
     * @return array
     */
    public function filterResourceHints(array $hints, string $relation): array
    {
        if ('dns-prefetch' === $relation) {
            $hints[] = $this->bucketUrl;
        }

        return $hints;
    }

    /**
     * Get a local copy of the file.
     *
     * @param  string $file
     * @return string
     */
    public function copyImageFromS3(string $file): string
    {
        if (!function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $temp = wp_tempnam($file);

        copy($file, $temp);
        return $temp;
    }

    /**
     * Get original upload dir
     *
     * @return array
     */
    public function getOriginalUploadDir(): array
    {
        if (empty($this->originalUploadDir)) {
            return $this->originalUploadDir = wp_upload_dir();
        }

        return $this->originalUploadDir;
    }
}
