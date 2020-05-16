<?php

namespace TinyPixel\Uploads;

use Psr\Container\ContainerInterface;
use Illuminate\Support\Collection;

/**
 * S3 Uploads
 *
 * @package TinyPixel\Uploads
 */
class Uploads
{
    /** Psr\Container\ContainerInterface */
    public $plugin;

    /**
     * Class constructor.
     *
     * @param Psr\Container\ContainerInterface $plugin
     */
    public function __construct(
        ContainerInterface $plugin,
        Collection $collection
    ) {
        $this->plugin = $plugin;
        $this->collection = $collection;
        $this->storage = $plugin->get('storage');
    }

    /**
     * Initialize class.
     *
     * @return void
     */
    public function init(): void
    {
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
    public function filterUploadDir(array $directories): array
    {
        $directories = $this->collection::make($directories);
        $s3Url = join("://", ["s3", $this->plugin->get('storage.s3.config')->bucket]);

        return apply_filters('s3_directories', (
            $directories
                ->put('path', str_replace(
                    $this->plugin->get('paths.content'),
                    $this->plugin->get('storage.s3.config')->bucketPath,
                    $directories->get('path')
                ))
                ->put('basedir', str_replace(
                    $this->plugin->get('paths.content'),
                    $this->plugin->get('storage.s3.config')->bucketPath,
                    $directories->get('basedir')
                ))
                ->put('url', str_replace(
                    $s3Url,
                    $this->plugin->get('storage.s3.config')->bucketUrl,
                    $directories->get('path')
                ))
                ->put('baseurl', str_replace(
                    $s3Url,
                    $this->plugin->get('storage.s3.config')->bucketUrl,
                    $directories->get('path')
                ))
                ->toArray()
        ));
    }

    /**
     * Delete attachment files from S3
     *
     * @param  int postId
     * @return void
     */
    public function deleteAttachment(int $postId): void
    {
        $attachment = (object) [
            'source' => get_attached_file($postId),
            'sizes' => $this->plugin->get('collection')::make(
                wp_get_attachment_metadata($postId)['sizes']
            ),
        ];

        $attachment->sizes->each(
            function ($size) use ($attachment) {
                $this->plugin->get('fs.cloud')->delete(
                    str_replace(basename($attachment), $size['file'], $attachment)
                );
            }
        );

        $this->plugin->get('fs.cloud')->delete($attachment);
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
        $file = (object) $file;

        $writePath = join('/', [
            $this->plugin->get('paths.upload')->baseDir,
            'tmp',
            basename($file->tmp_name),
        ]);

        $this->filesystem->copy($file->tmp_name, $writePath);
        $this->filesystem->delete($file->tmp_name);
        $file->tmp_name = $writePath;

        return (array) $file;
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

        if ($tmp = $this->copyImageFromS3($file)) {
            $meta = wp_read_image_metadata($tmp);
            add_filter('wp_read_image_metadata', [$this, 'filterMetadata'], 10, 2);
            $this->filesystem->delete($tmp);
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
        $relation === 'dns-prefetch' && array_push($hints, ...[
            $this->plugin->get('storage.s3.config')->bucketUrl
        ]);

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
        $this->plugin->make('wp.includes.file');

        $this->filesystem->copy($file, wp_tempnam($file));

        return wp_tempnam($file);
    }
}
