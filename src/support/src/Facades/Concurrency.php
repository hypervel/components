<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Concurrency\ConcurrencyManager;

/**
 * @method static mixed driver(string|null $name = null)
 * @method static \Hypervel\Concurrency\CoroutineDriver createCoroutineDriver()
 * @method static \Hypervel\Concurrency\ProcessDriver createProcessDriver()
 * @method static \Hypervel\Concurrency\SyncDriver createSyncDriver()
 * @method static string getDefaultInstance()
 * @method static void setDefaultInstance(string $name)
 * @method static array getInstanceConfig(string $name)
 * @method static mixed instance(string|null $name = null)
 * @method static \Hypervel\Concurrency\ConcurrencyManager forgetInstance(array|string|null $name = null)
 * @method static void purge(string|null $name = null)
 * @method static \Hypervel\Concurrency\ConcurrencyManager extend(string $name, \Closure $callback)
 * @method static \Hypervel\Concurrency\ConcurrencyManager setApplication(\Hypervel\Contracts\Foundation\Application $app)
 * @method static array run(\Closure|array $tasks)
 * @method static \Hypervel\Support\Defer\DeferredCallback defer(\Closure|array $tasks)
 *
 * @see \Hypervel\Concurrency\ConcurrencyManager
 */
class Concurrency extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return ConcurrencyManager::class;
    }
}
