<?php

namespace TinyPixel\Storage\Plugin;

use Psr\Container\ContainerInterface;

/**
 * The plugin runtime.
 */
class Runtime
{
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
        $this->disks = $plugin->get('disks');

        add_action('add_attachment', [$this, 'addAttachment'], 20, 1);
        add_filter('wp_generate_attachment_metadata', [$this, 'handleAttachmentMetadata'], 20, 2);
        add_filter('wp_update_attachment_metadata', [$this, 'handleAttachmentMetadata'], 20, 2);
        add_action('delete_attachment', [$this, 'deleteAttachment']);

		add_filter('wp_image_editors', [$this, 'wpImageEditors'], 9);
		add_filter('wp_read_image_metadata', [$this, 'wpReadImageMetadata'], 10, 2);
        add_filter('wp_resource_hints', [$this, 'wpResourceHints'], 10, 2);

        remove_filter('admin_notices', 'wpthumb_errors');
    }

    /**
     * Format a WordPress attachment as an s3 object
     */
    public function attachment(string $file)
    {
        $fs = $this->plugin->get('fs');

        return (object) [
            's3' => str_replace($fs->wp->basedir, $fs->s3->basedir, $file),
            'local' => str_replace($fs->wp->basedir, $fs->local->basedir, $file),
        ];
    }

    /**
     * Add attachment
     *
     * @param  array
     * @param  int
     * @return array
     */
    public function addAttachment(int $postId)
    {
        $file = $this->attachment(get_attached_file($postId, true));

        if (! $this->disks->has($file->s3)) {
            $this->disks->copy($file->local, $file->s3);
            $this->disks->delete($file->local);
        }

        return $file->s3;
    }

    /**
     * Handle attachment metadata
     */
    public function handleAttachmentMetadata($file, $postId): array
    {
        $this->collection::make($file['sizes'])->each(function ($size) {
            // ...
        });

        return $file;
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

        array_unshift($editors, '\TinyPixel\Storage\ImageEditor\ImagickEditor');

        return $editors;
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
        $relation === 'dns-prefetch' && array_push(
            $hints,
            $this->plugin->get('disk.s3.config')->bucketUrl
        );

        return $hints;
    }
}
