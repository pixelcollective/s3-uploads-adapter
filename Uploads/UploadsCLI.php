<?php
namespace TinyPixel\Uploads;

use \WP_CLI;
use \WP_CLI_Command;
use \Exception;
use AWS\Command;
use Aws\S3\Transfer;
use TinyPixel\Uploads\Uploads;

/**
 * S3 Uploads CLI
 *
 * @package TinyPixel\Uploads
 */
class UploadsCLI extends WP_CLI_Command
{
    /**
     * Class constructor.
     */
    public function __construct()
    {
        $this->uploads = Uploads::getInstance();
        $this->s3      = $this->uploads->getClient();
    }

    /**
     * List files in the S3 bucket
     *
     * @param  array command arguments
     * @return void
     */
    public function ls(array $args) : void
    {
        $s3 = Uploads::getInstance()->getClient();

        $prefix = '';

        if (strpos($this->uploads->bucket, '/')) {
            $prefix = trailingslashit(str_replace(strtok($this->uploads->bucket, '/') . '/', '', $this->uploads->bucket));
        }

        if (isset($args[0])) {
            $prefix .= trailingslashit(ltrim($args[0], '/'));
        }

        try {
            $objects = $s3->getIterator('ListObjects', [
                'Bucket' => strtok($this->uploads->bucket, '/'),
                'Prefix' => $prefix,
            ]);

            foreach ($objects as $object) {
                WP_CLI::line(str_replace($prefix, '', $object['Key']));
            }
        } catch (\Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Copy files to / from the uploads directory. Use s3://bucket/location for S3
     *
     * @param  array $args [from, to]
     * @return void
     */
    public function cp($args)
    {
        $from = $args[0];
        $to   = $args[1];

        if (is_dir($from)) {
            $this->recurse_copy($from, $to);
        } else {
            copy($from, $to);
        }

        WP_CLI::success("Completed copy from {$from} to {$to}");
    }

    /**
     * Upload a directory to S3
     *
     * @subcommand upload-directory
     * @synopsis <from> [<to>] [--concurrency=<concurrency>] [--verbose]
     * @return void
     */
    public function upload_directory(array $args, array $args_assoc) : void
    {
        if (!$args[0] && $args[1]) {
            throw new \Exception('Both from and to options are required');
        }

        $from = $args[0];
        $to   = $args[1];

        $args_assoc = wp_parse_args($args_assoc, [
            'concurrency' => 5,
            'verbose'     => false,
        ]);

        $transfer = [
            'concurrency' => $args_assoc['concurrency'],
            'debug'       => (bool) $args_assoc['verbose'],
            'before'      => function (Command $command) {
                if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload'], true)) {
                    $command['ACL'] = $this->uploads->acl;
                }
            },
        ];

        try {
            $manager = new Transfer($this->s3, $from, "s3://{$this->uploads->bucket}/{$to}", $transfer);

            $manager->transfer();
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Delete files from S3
     *
     * @synopsis <path> [--regex=<regex>]
     */
    public function rm(array $args, array $args_assoc, string $prefix = '')
    {
        $regex = isset($args_assoc['regex']) ? $args_assoc['regex'] : '';

        if (strpos($this->uploads->bucket, '/')) {
            $prefix = trailingslashit(
                str_replace(strtok($this->uploads->bucket, '/') . '/', '', $this->uploads->bucket)
            );
        }

        if (isset($args[0])) {
            $prefix .= ltrim($args[0], '/');
            if (strpos($args[0], '.') === false) {
                $prefix = trailingslashit($prefix);
            }
        }

        try {
            $objects = $s3->deleteMatchingObjects(strtok($this->s3->bucket, '/'), $prefix, $regex, [
                'before_delete', function () {
                    WP_CLI::line("Deleting file");
                }
            ]);
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }

        WP_CLI::success("Successfully deleted {$prefix}");
    }

    /**
     * Recursively copy from src to destination
     *
     * @param  string $src
     * @param  string $destination
     * @return void
     */
    private function recurse_copy(string $src, string $dst) : void
    {
        $dir = opendir($src);

        @mkdir($dst);

        while (false !== ($file = readdir($dir))) {
            if ('.' !== $file && '..' !== $file) {
                if (is_dir("{$src}/{$file}")) {
                    $this->recurse_copy("{$src}/{$file}", "{$dst}/{$file}");
                } else {
                    WP_CLI::line("Copying from {$src}/{$file} to {$dst}/{$file}");

                    copy("{$src}/{$file}", "{$dst}/{$file}");
                }
            }
        }

        closedir($dir);
    }
}
