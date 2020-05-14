<?php

namespace TinyPixel\Uploads;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use League\Flysystem\MountManager;

/**
 * S3 Uploads
 *
 * @package TinyPixel\Uploads
 */
class Uploads
{
    /** @var TinyPixel\Uploads\Uploads */
    private static $instance;

    /**
     * Singleton constructor.
     *
     * @return \TinyPixel\Uploads\Uploads
     */
    public static function getInstance()
    {
        if (! self::$instance) {
            self::$instance = new Uploads();
        }

        return self::$instance;
    }

    /**
     * Class constructor.
     */
    public function __construct()
    {
        $this->region = defined('S3_UPLOADS_REGION') ? S3_UPLOADS_REGION : null;
        $this->bucket = defined('S3_UPLOADS_BUCKET') ? S3_UPLOADS_BUCKET : null;
        $this->key = defined('S3_UPLOADS_KEY') ? S3_UPLOADS_KEY : null;
        $this->secret = defined('S3_UPLOADS_SECRET') ? S3_UPLOADS_SECRET : null;
        $this->endpoint = defined('S3_UPLOADS_ENDPOINT') ? S3_UPLOADS_ENDPOINT : null;
        $this->bucketPath = "s3://{$this->bucket}/app";
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
        $this->client = new S3Client([
            'credentials' => [
                'key'    => 'OLJULP77EDETUOXLXMZN',
                'secret' => 'cGdj1wCKcubqNw4s0h5GFz6Aq+qFBT9qDuHY+HTK8tI',
            ],
            'region' => 'nyc3',
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'version' => 'latest',
        ]);

        $this->local = new Local(WP_CONTENT_DIR);
        $this->s3 = new AwsS3Adapter($this->client, 'techloris-cdn', '/app');

        $this->filesystem = new MountManager([
            'local' => new Filesystem($this->local),
            's3' => new Filesystem($this->s3),
        ]);

        add_filter('upload_dir', [$this, 'filterUploadDir']);
        add_filter('wp_image_editors', [$this, 'filterEditors'], 9);
        add_filter('wp_read_image_metadata', [$this, 'filterMetadata'], 10, 2);
        add_filter('wp_resource_hints', [$this, 'filterResourceHints'], 10, 2);
        add_action('delete_attachment', [$this, 'deleteAttachment']);
        add_action('wp_handle_sideload_prefilter', [$this, 'sideload']);

        remove_filter('admin_notices', 'wpthumb_errors');
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

        if (! empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                $this->filesystem->delete(
                    str_replace(basename($file), $size['file'], $file)
                );
            }
        }

        $this->filesystem->delete($file);
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

        $this->filesystem->copy($file['tmp_name'], $finalPath);
        $this->filesystem->delete($file['tmp_name']);

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

            $this->filesystem->delete($temp);
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
        if ($relation === 'dns-prefetch') {
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
        if (! function_exists('wp_tempnam')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $temp = wp_tempnam($file);

        $this->filesystem->copy($file, $temp);
        return $temp;
    }
}
