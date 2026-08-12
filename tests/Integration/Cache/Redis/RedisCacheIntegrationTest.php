<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

/**
 * Integration tests ported from Laravel's RedisCacheIntegrationTest.
 *
 * Tests core Redis cache behavior against a real Redis connection.
 */
class RedisCacheIntegrationTest extends RedisCacheIntegrationTestCase
{
    public function testRedisCacheAddTwice()
    {
        $cache = $this->cache();
        $this->assertTrue($cache->add('k', 'v', 3600));
        $this->assertFalse($cache->add('k', 'v', 3600));
        $this->assertGreaterThan(3500, $this->store()->connection()->ttl($this->store()->getPrefix() . 'k'));
    }

    /**
     * Breaking change.
     */
    public function testRedisCacheAddFalse()
    {
        $cache = $this->cache();
        $cache->forever('k', false);
        $this->assertFalse($cache->add('k', 'v', 60));
        $this->assertEquals(-1, $this->store()->connection()->ttl($this->store()->getPrefix() . 'k'));
    }

    /**
     * Breaking change.
     */
    public function testRedisCacheAddNull()
    {
        $cache = $this->cache();
        $cache->forever('k', null);
        $this->assertFalse($cache->add('k', 'v', 60));
    }
}
