<?php
namespace TinyPixel\SUP;

use Aws\S3\S3Client;
use TinyPixel\SUP\StreamWrapper;
use TinyPixel\SUP\LocalStreamWrapper;
use TinyPixel\SUP\ImageEditorImagick;

/**
 * S3 Uploads
 *
 * @package TinyPixel\SUP
 */
class Uploads
{
    /** @var TinyPixel\SUP\Uploads */
    private static $instance;

    /**
     * Singleton constructor.
     *
     * @param  string region
     * @param  string bucket
     * @param  string key
     * @param  string secret
     * @param  string endpoint
     * @param  string signature
     * @return \TinyPixel\SUP\Uploads
     */
    public static function getInstance(
        string $region    = null,
        string $bucket    = null,
        string $key       = null,
        string $secret    = null,
        string $endpoint  = null,
        string $signature = null
    ) {
        if (!self::$instance) {
            self::$instance = new Uploads(
                $region    ?: defined('S3_UPLOADS_REGION')    ? S3_UPLOADS_REGION    : null,
                $bucket    ?: defined('S3_UPLOADS_BUCKET')    ? S3_UPLOADS_BUCKET    : null,
                $key       ?: defined('S3_UPLOADS_KEY')       ? S3_UPLOADS_KEY       : null,
                $secret    ?: defined('S3_UPLOADS_SECRET')    ? S3_UPLOADS_SECRET    : null,
                $endpoint  ?: defined('S3_UPLOADS_ENDPOINT')  ? S3_UPLOADS_ENDPOINT  : null,
                $signature ?: defined('S3_UPLOADS_SIGNATURE') ? S3_UPLOADS_SIGNATURE : 'v4',
            );
        }

        return self::$instance;
    }

    /**
     * Class constructor.
     *
     * @param string region
     * @param string bucket
     * @param string key
     * @param string secret
     * @param string endpoint
     * @param string signature
     */
    public function __construct(
        string $region,
        string $bucket,
        string $key,
        string $secret,
        string $endpoint,
        string $signature
    ) {
        $this->env    = defined('WP_ENV')                ? WP_ENV                : null;
        $this->acl = defined('S3_UPLOADS_OBJECT_ACL')    ? S3_UPLOADS_OBJECT_ACL : 'public-read';
        $this->local  = defined('S3_UPLOADS_USE_LOCAL')  ? S3_UPLOADS_USE_LOCAL  : false;

        $this->region    = $region;
        $this->bucket    = $bucket;
        $this->key       = $key;
        $this->secret    = $secret;
        $this->endpoint  = $endpoint;
        $this->signature = $signature;

        $this->bucketPath = "s3://{$this->bucket}/{$this->env}/app";
        $this->bucketUrl  = "https://{$this->bucket}.{$this->region}.cdn.digitaloceanspaces.com";

        $this->editor     = '\\TinyPixel\\SUP\\ImageEditorImagick';
    }

    /**
     * Setup the hooks, urls filtering etc for S3 Uploads
     *
     * @return void
     */
    public function setup() : void
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
    public function filterParameters() : void
    {
        $this->env        = apply_filter('sup_env',         $this->env);
        $this->acl        = apply_filter('sup_acl',         $this->acl);
        $this->local      = apply_filter('sup_local',       $this->local);
        $this->region     = apply_filter('sup_region',      $this->region);
        $this->bucket     = apply_filter('sup_bucket',      $this->bucket);
        $this->key        = apply_filter('sup_key',         $this->key);
        $this->secret     = apply_filter('sup_secret',      $this->secret);
        $this->endpoint   = apply_filter('sup_endpoint',    $this->endpoint);
        $this->signature  = apply_filter('sup_signature',   $this->signature);
        $this->bucketPath = apply_filter('sup_bucket_path', $this->bucketPath);
        $this->bucketUrl  = apply_filter('sup_bucket_url',  $this->bucketUrl);
        $this->editor     = apply_filter('sup_editor',      $this->editor);
    }

    /**
     * Register the stream wrapper for s3
     *
     * @return void
     */
    public function registerStreamWrapper() : void
    {
        if ($this->local) {
            stream_wrapper_register('s3', '\\TinyPixel\\SUP\\LocalStreamWrapper', STREAM_IS_URL);
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
    public function configureClient() : void
    {
        $clientParams = apply_filters('s3_uploads_s3_client_params', [
            'version'     => "latest",
            'signature'   => $this->signature,
            'region'      => $this->region,
            'endpoint'    => $this->endpoint,
            'csm'         => false,
            'credentials' => ['key' => $this->key, 'secret' => $this->secret],
        ]);

        $this->client = S3Client::factory($clientParams);
    }

    /**
     * Get S3 client instance
     *
     * @return Aws\S3\S3Client
     */
    public function getClient() : \Aws\S3\S3Client
    {
        if ($this->client) {
            return $this->client;
        } else {
            throw new \Exception('AWS\\S3\\S3Client not available.');
        }
    }

    /**
     * Filter uploads dir.
     *
     * @param  array dirs
     * @return array
     */
    public function filterUploadDir(array $dirs) : array
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
    public function deleteAttachment(int $postId) : void
    {
        $meta = wp_get_attachment_metadata($post_id);
        $file = get_attached_file($post_id);

        if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeinfo) {
                $intermediate_file = str_replace(basename($file), $sizeinfo['file'], $file);
                unlink($intermediateFile);
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
    public function sideload(array $file) : array
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
    public function filterResourceHints(array $hints, string $relation) : array
    {
        if ('dns-prefetch' === $relation_type) {
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
    public function copyImageFromS3(string $file) : string
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
    public function getOriginalUploadDir() : array
    {
        if (empty($this->originalUploadDir)) {
            return $this->originalUploadDir = wp_upload_dir();
        }

        return $this->originalUploadDir;
    }
}
