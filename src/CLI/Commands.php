<?php
namespace TinyPixel\Storage;

use \WP_CLI;
use Psr\Container\ContainerInterface;

/**
 * S3 Uploads CLI
 */
class Commands extends \WP_CLI_Command
{
    /**
     * Class constructor.
     */
    public function __construct(ContainerInterface $plugin)
    {
        self::$plugin = $plugin;
        self::$storage = $plugin->get('storage');
    }

    /**
     * List files in the S3 bucket
     *
     * @return void
     */
    public function ls() : void
    {
        try {
            self::$plugin->get('collection')::make(
                self::$storage->listContents('s3://')
            )->each(function ($item) {
                WP_CLI::line("{$item['path']} ({$item['type']})");
            });
        } catch (FilesystemError $exception) {
            WP_CLI::error($exception->getMessage());
        }
    }

    /**
     * Upload a directory to S3
     *
     * @synopsis <from> [<to>]
     * @return void
     */
    public function upload(array $args) : void
    {
        if (! $args[1]) {
            throw new \Exception('Both from and to options are required');
        }

        $from = $args[0];
        $to   = $args[1];

        try {
            $this->filesystem->copy("local://{$from}", "s3://{$to}");
        } catch (FilesystemError | UnableToCopyFile $exception) {
            WP_CLI::error($exception->getMessage());
        }
    }

    /**
     * Delete files from S3
     *
     * @synopsis <path>
     */
    public function remove(array $args)
    {
        try {
            $this->filesystem->delete("{$this->s3->bucketPath}/{$args[0]}");
        } catch (FilesystemError | UnableToDeleteFile $exception) {
            WP_CLI::error($exception->getMessage());
        }

        WP_CLI::success("Successfully deleted {$args[0]}");
    }
}
