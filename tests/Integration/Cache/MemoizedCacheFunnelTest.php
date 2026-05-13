<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Support\Facades\Cache;

class MemoizedCacheFunnelTest extends CacheFunnelTestCase
{
    protected function cache(): Repository
    {
        return Cache::memo('array');
    }
}
