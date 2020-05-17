<?php

namespace TinyPixel\Storage\ImageEditor;

use \Imagick;
use \WP_Error;

/**
 * Image Editor
 */
class Editor extends \WP_Image_Editor_Imagick
{
    /** \Imagick */
    protected $image;

    protected $tmpFileToCleanup = null;

    /**
     * Hold on to a reference of all temp local files.
     *
     * @var array
     */
    protected $tmpFilesToCleanup = [];

    /**
     * Loads image from $this->file into new Imagick Object.
     *
     * @return true|WP_Error True if loaded; WP_Error on failure.
     */
    public function load()
    {
        if ($this->image instanceof Imagick) {
            return true;
        }

        if (! is_file($this->file) && !preg_match('|^https?://|', $this->file)) {
            return new WP_Error(
                'error_loading_image',
                 __('File doesn&#8217;t exist?'),
                 $this->file
            );
        }

        $this->uploads = (object) wp_upload_dir();

        if (strpos($this->file, $this->uploads->baseDir) !== 0) {
            return parent::load();
        }

        $tmpFilename = tempnam(get_temp_dir(), 's3-uploads');

        $this->tmpFilesToCleanup[] = $tmpFilename;

        copy($this->file, $tmpFilename);

        $this->remoteFilename = $this->file;
        $this->file = $tmpFilename;

        $result = parent::load();

        $this->file = $this->remoteFilename;

        return $result;
    }

    /**
     * Imagick by default can't handle s3:// paths
     * for saving images. We have instead save it to a file file,
     * then copy it to the s3:// path as a workaround.
     */
    protected function _save($image, $filename = null, $mime_type = null)
    {
        list($filename, $extension, $mime_type) = $this->get_output_format($filename, $mime_type);

        if (! $filename) {
            $filename = $this->generate_filename(null, null, $extension);
        }

        $this->uploads = wp_upload_dir();

        if (strpos($filename, $this->uploads->baseDir) === 0) {
            $tmpFilename = tempnam(get_temp_dir(), 's3-uploads');
        }

        $save = parent::_save($image, $tmpFilename, $mime_type);

        if (is_wp_error($save)) {
            unlink($tmpFilename);
            return $save;
        }

        $copy_result = copy($save['path'], $filename);

        unlink($save['path']);
        unlink($tmpFilename);

        if (! $copy_result) {
            return new \WP_Error('unable-to-copy-to-s3', 'Unable to copy the temp image to S3');
        }

        return [
            'path' => $filename,
            'width' => $this->size['width'],
            'height' => $this->size['height'],
            'file' => wp_basename(
                apply_filters(
                    'image_make_intermediate_size',
                    $filename
                )
            ),
            'mime-type' => $mime_type,
        ];
    }

    /**
     * Class destructor.
     */
    public function __destruct()
    {
        array_map('unlink', $this->tmpFilesToCleanup);

        parent::__destruct();
    }
}
