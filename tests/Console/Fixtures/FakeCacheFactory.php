<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Fixtures;

use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Repository;
use UnitEnum;

class FakeCacheFactory implements Factory
{
    /**
     * The last store name that was requested.
     */
    public UnitEnum|string|null $name = null;

    /**
     * Create a new fake cache factory instance.
     */
    public function __construct(public ?Repository $repository = null)
    {
    }

    /**
     * Get a cache store instance by name.
     */
    public function store(UnitEnum|string|null $name = null): Repository
    {
        $this->name = $name;

        return $this->repository;
    }
}
