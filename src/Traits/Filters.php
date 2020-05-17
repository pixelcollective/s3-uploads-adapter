<?php

namespace TinyPixel\Storage\Traits;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Psr\Container\ContainerInterface;

/**
 * Filters
 */
trait Filters
{
    /** @var */
    public $args = [];

    /**
     * Instantiate filters.
     */
    public function applyFilters(ContainerInterface $container)
    {
        $container->get('wp.filters')->each(function ($filter) {
            if (method_exists($this, $method = Str::camel($filter->name))) {
                add_filter($filter->name, [$this, $method], ...$this->getArgs($method));
            }
        });
    }

    /**
     * Get filter arguments merged from class properties
     * and whatever array values are returned from setArgs
     *
     * @return array
     */
    protected function getArgs(string $key): array
    {
        $this->args = Collection::make($this->args)->mergeRecursive($this->setArgs());

        return $this->args->has($key) ? $this->args->get($key) : [];
    }

    /**
     * Remove filters.
     *
     * @param  array
     * @return void
     */
    protected function removeFilters(array $filters): void
    {
        foreach($filters as $k => $v) {
            remove_filter($k, $v);
        }
    }
}
