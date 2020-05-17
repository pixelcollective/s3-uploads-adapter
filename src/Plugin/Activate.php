<?php

namespace TinyPixel\Storage\Plugin;

/**
 * The plugin activation class.
 */
class Activate
{
    /**
     * Activate the plugin.
     */
    public function __invoke(): void
    {
        \flush_rewrite_rules();
    }
}
