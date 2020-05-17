<?php

namespace TinyPixel\Storage\Traits;

use Illuminate\Support\Collection;

/**
 * Actions
 */
trait Actions
{
    /**
     * Instantiate actions.
     */
    public function instantiateActions(Collection $collection)
    {
        $this->actions = $collection::make(
            json_decode(file_get_contents("{$this->getBaseDir()}/vendor/johnbillion/wp-hooks/hooks/actions.json"))
        )->each(function ($action) {
            if (method_exists($this, $action->name)) {
                add_filter($action->name, [$this, $action->name]);
            }
        });
    }
}
