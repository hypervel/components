<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\SessionStore;
use Hypervel\Cache\StackStore;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Tests\TestCase;

class FunnelUnsupportedStoresTest extends TestCase
{
    public function testStackStoreDoesNotImplementLockProvider(): void
    {
        $this->assertFalse(is_subclass_of(StackStore::class, LockProvider::class));
    }

    public function testSessionStoreDoesNotImplementLockProvider(): void
    {
        $this->assertFalse(is_subclass_of(SessionStore::class, LockProvider::class));
    }
}
