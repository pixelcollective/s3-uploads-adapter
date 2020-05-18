<?php
namespace TinyPixel\Storage\CLI;

use Psr\Container\ContainerInterface;

/**
 * Storage CLI
 */
class Storage extends \WP_CLI_Command
{
    /**
     * Class constructor.
     */
    public function __construct(ContainerInterface $plugin)
    {
        $this->cli = $plugin->get('wp.cli');
    }
}
