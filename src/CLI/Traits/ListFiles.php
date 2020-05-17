<?php

namespace TinyPixel\Storage\CLI\Traits;

/**
 * List files.
 */
trait ListFiles
{
    /**
     * List disk contents.
     */
    public function list()
    {
        $path = '';
        $recursive = true;

        $this->collection::make(
            $this->disk->listContents($path, $recursive)
        )->each(function ($item) {
            $this->cli::log("{$item['path']} ({$item['type']})");
        });
    }
}
