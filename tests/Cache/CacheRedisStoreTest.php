<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class CacheRedisStoreTest extends TestCase
{
    use VerifiesDoubles;

    public function testSupportsFlushingLocksRequiresSeparateLockConnection(): void
    {
        $store = $this->getRedis();

        $this->assertFalse($store->supportsFlushingLocks());

        $store->setLockConnection('locks');

        $this->assertTrue($store->supportsFlushingLocks());
    }

    public function testGetAndSetPrefix(): void
    {
        $store = $this->getRedis();
        $this->assertSame('prefix:', $store->getPrefix());
        $store->setPrefix('foo');
        $this->assertSame('foo', $store->getPrefix());
        $store->setPrefix(null);
        $this->assertEmpty($store->getPrefix());
    }

    /**
     * Create a Redis store without opening a connection.
     */
    protected function getRedis(): RedisStore
    {
        return new RedisStore(Double::for(Factory::class), 'prefix:');
    }
}
