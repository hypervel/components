<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Concurrency\Concurrency as ConcurrencyInstance;

/**
 * @method static array run(\Closure|array $tasks)
 * @method static \Hypervel\Support\Defer\DeferredCallback defer(\Closure|array $tasks)
 *
 * @see \Hypervel\Concurrency\Concurrency
 */
class Concurrency extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return ConcurrencyInstance::class;
    }
}
