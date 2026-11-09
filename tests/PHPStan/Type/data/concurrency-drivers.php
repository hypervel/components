<?php

declare(strict_types=1);

namespace ConcurrencyDrivers;

use Hypervel\Concurrency\ConcurrencyManager;
use Hypervel\Support\Facades\Concurrency;

use function PHPStan\Testing\assertType;

enum Driver: string
{
    case Isolated = 'process';
}

class TenantConcurrencyManager extends ConcurrencyManager
{
}

function test(ConcurrencyManager $manager, TenantConcurrencyManager $tenantManager, bool $condition, string $name): void
{
    assertType('Hypervel\Concurrency\CoroutineDriver', Concurrency::driver());
    assertType('Hypervel\Concurrency\CoroutineDriver', Concurrency::driver(''));
    assertType('Hypervel\Concurrency\CoroutineDriver', Concurrency::driver('coroutine'));
    assertType('Hypervel\Concurrency\SyncDriver', Concurrency::driver('sync'));
    assertType('Hypervel\Concurrency\ProcessDriver', Concurrency::driver(Driver::Isolated));
    assertType('Hypervel\Concurrency\CoroutineDriver|Hypervel\Concurrency\SyncDriver', Concurrency::driver($condition ? 'coroutine' : 'sync'));
    assertType('Hypervel\Concurrency\SyncDriver', $manager->driver('sync'));

    // Drivers the analysis cannot type keep the declaration.
    assertType('Hypervel\Contracts\Concurrency\Driver', Concurrency::driver($name));
    assertType('Hypervel\Contracts\Concurrency\Driver', Concurrency::driver('missing'));
    assertType('Hypervel\Contracts\Concurrency\Driver', Concurrency::driver('0'));
    assertType('Hypervel\Contracts\Concurrency\Driver', $tenantManager->driver('sync'));
}
