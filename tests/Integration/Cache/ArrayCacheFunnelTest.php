<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Support\Facades\Cache;
use Hypervel\Testbench\Attributes\WithConfig;

#[WithConfig('cache.default', 'array')]
class ArrayCacheFunnelTest extends CacheFunnelTestCase
{
    protected function cache(): Repository
    {
        return Cache::store('array');
    }
}
