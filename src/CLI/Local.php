<?php
namespace TinyPixel\Storage\CLI;

use Psr\Container\ContainerInterface;
use TinyPixel\Storage\CLI\Traits\ListFiles;

/**
 * Interact with the local filesystem.
 */
class Local extends \WP_CLI_Command
{
    use ListFiles;

    /**
     * Class constructor.
     */
    public function __construct(ContainerInterface $plugin)
    {
        $this->cli = $plugin->get('wp.cli');
        $this->collection = $plugin->get('collection');
        $this->disk = $plugin->get('storage.filesystem.local');
    }
}
