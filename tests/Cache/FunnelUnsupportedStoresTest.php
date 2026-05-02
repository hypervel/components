<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\SessionStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\SwooleStore;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Tests\TestCase;

class FunnelUnsupportedStoresTest extends TestCase
{
    public function testSwooleStoreDoesNotImplementLockProvider()
    {
        $this->assertFalse(is_subclass_of(SwooleStore::class, LockProvider::class));
    }

    public function testStackStoreDoesNotImplementLockProvider()
    {
        $this->assertFalse(is_subclass_of(StackStore::class, LockProvider::class));
    }

    public function testSessionStoreDoesNotImplementLockProvider()
    {
        $this->assertFalse(is_subclass_of(SessionStore::class, LockProvider::class));
    }
}
