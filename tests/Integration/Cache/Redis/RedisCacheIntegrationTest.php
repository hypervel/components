<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\RateLimiter;

/**
 * Integration tests ported from Laravel's RedisCacheIntegrationTest.
 *
 * Tests core Redis cache behavior (add, rate limiter) against a real Redis connection.
 *
 * @internal
 * @coversNothing
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

    public function testRedisCacheRateLimiter()
    {
        $rateLimiter = new RateLimiter($this->cache());

        $this->assertFalse($rateLimiter->tooManyAttempts('key', 1));
        $this->assertEquals(1, $rateLimiter->hit('key', 60));
        $this->assertTrue($rateLimiter->tooManyAttempts('key', 1));
        $this->assertFalse($rateLimiter->tooManyAttempts('key', 2));
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
