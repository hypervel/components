<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Support\Facades\Cache;
use Hypervel\Testbench\Attributes\WithMigration;

#[WithMigration('cache')]
class DatabaseCacheFunnelTest extends CacheFunnelTestCase
{
    use LazilyRefreshDatabase;

    protected function cache(): Repository
    {
        return Cache::store('database');
    }
}
