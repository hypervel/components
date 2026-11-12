<?php

namespace FacadesL1220;

use App\DummyFacade;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\Facades\Request;
use Hypervel\Support\Facades\Storage;

use function PHPStan\Testing\assertType;

function test(): void
{
    // Hypervel: foo.bar is definitely absent from the analyzed configuration, so collection() throws.
    assertType('*ERROR*', Config::collection('foo.bar'));

    assertType('Hypervel\Contracts\Cache\Repository', Cache::driver());
    assertType('\'123\'', Cache::remember(
        key: 'cache-key',
        ttl: 60,
        callback: static fn (): string => '123',
    ));
    assertType('123', Cache::rememberForever(
        key: 'cache-key',
        callback: static fn (): int => 123,
    ));
}
