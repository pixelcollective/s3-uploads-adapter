<?php

namespace TinyPixel\Storage\Traits;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;

/**
 * Actions
 */
trait Actions
{
    /**
     * Instantiate filters.
     *
     * @param  Collection
     * @return void
     */
    public function applyActions(Collection $actions): void
    {
        $actions->each(function ($filter) {
            if (method_exists($this, $method = Str::camel($filter->name))) {
                add_action($filter->name, [$this, $method], $this->getActionArgs($method));
            }
        });
    }

    /**
     * Get any additional arguments
     *
     * @param  string
     * @return array|null
     */
    protected function getActionArgs(string $key)
    {
        return trait_exists('HookArguments', false) ? $this->getHookArguments($key) : null;
    }

    /**
     * Remove filters.
     *
     * @param  array
     * @return void
     */
    protected function removeActions(array $filters): void
    {
        foreach($filters as $k => $v) {
            remove_action($k, $v);
        }
    }
}
