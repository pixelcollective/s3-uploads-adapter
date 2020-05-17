<?php

namespace TinyPixel\Storage\Plugin;

use Psr\Container\ContainerInterface;
use TinyPixel\Storage\Traits\Filters;

/**
 * The plugin runtime.
 */
class Runtime
{
    use Filters;

    /** Psr\Container\ContainerInterface */
    public $plugin;

    /**
     * Class constructor.
     *
     * @param Psr\Container\ContainerInterface $plugin
     */
    public function __construct(ContainerInterface $plugin) {
        $this->plugin = $plugin;
        $this->collection = $plugin->get('collection');
        $this->storage = $plugin->get('storage');
    }

    /**
     * Remove filters.
     *
     * @return array
     */
    public function removeFilters(): array
    {
        return ['admin_notices' => 'wpthumb_errors'];
    }

    /**
     * Associate filter arguments with class methods.
     *
     * @return array
     */
    public function setArgs(): array
    {
        return [
            'uploadDir' => [9],
            'wpImageEditors' => [9],
            'wpResourcesHints' => [10, 2],
            'deleteAttachment' => [10, 2],
            'wpReadImageMetadata' => [10, 2],
        ];
    }

    /**
     * Filter uploads dir.
     *
     * @param  array dirs
     * @return array
     */
    public function uploadDir(array $directories): array
    {
        $directories = $this->collection::make($directories);

        return apply_filters('s3_directories', (
            $directories
                ->put('path', str_replace(
                    $this->plugin->get('plugin.fs.content'),
                    $this->plugin->get('storage.s3.config')->bucketPath,
                    $directories->get('path')
                ))
                ->put('basedir', str_replace(
                    $this->plugin->get('plugin.fs.content'),
                    $this->plugin->get('storage.s3.config')->bucketPath,
                    $directories->get('basedir')
                ))
                ->put('url', str_replace(
                    $this->s3Url(),
                    $this->plugin->get('storage.s3.config')->bucketUrl,
                    $directories->get('path')
                ))
                ->put('baseurl', str_replace(
                    $this->s3Url(),
                    $this->plugin->get('storage.s3.config')->bucketUrl,
                    $directories->get('basedir')
                ))
                ->toArray()
        ));
    }

    /**
     * Get s3 URL
     */
    protected function s3Url(): string
    {
        return join('://', ['s3', $this->plugin->get('storage.s3.config')->bucket]);
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
            'sizes' => $this->collection::make(wp_get_attachment_metadata($postId)['sizes']),
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
     * Wordpress editors.
     *
     * @param  array editors
     * @return array editors
     */
    public function wpImageEditors($editors)
    {
        $position = array_search('WP_Image_Editor_Imagick', $editors);
        if ($position !== false) {
            unset($editors[$position]);
        }

        array_unshift($editors, $this->plugin->get('plugin.editor'));

        return $editors;
    }

    /**
     * Sideload prefilter.
     *
     * @param  array File array
     * @return array
     */
    public function wpHandleSideloadPrefilter(array $file): array
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
     * Read image metadata.
     *
     * @param array  $meta
     * @param string $file
     * @return array|bool
     */
    public function wpReadImageMetadata($meta, $file)
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
    public function wpResourceHints(array $hints = [], string $relation = ''): array
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
