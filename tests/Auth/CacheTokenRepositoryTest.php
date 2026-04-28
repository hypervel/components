<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\CacheTokenRepository;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CacheTokenRepositoryTest extends TestCase
{
    public function testCreateStoresHashedTokenAndReturnsPlainToken()
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 1, 12, 0, 0));

        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $hasher->shouldReceive('make')->once()->andReturnUsing(fn ($token) => 'hashed_' . $token);
        $cache->shouldReceive('forget')->once(); // delete() called first
        $cache->shouldReceive('put')->once()->andReturnUsing(function ($key, $value, $ttl) {
            $this->assertIsString($key);
            $this->assertIsArray($value);
            $this->assertCount(2, $value);
            $this->assertStringStartsWith('hashed_', $value[0]);
            $this->assertSame('2024-01-01 12:00:00', $value[1]);
            $this->assertSame(3600, $ttl);

            return true;
        });

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600);
        $token = $repository->create($user);

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token)); // SHA-256 hash is 64 hex chars

        Carbon::setTestNow();
    }

    public function testExistsReturnsTrueForValidToken()
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 1, 12, 0, 0));

        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $cache->shouldReceive('get')->once()->andReturn([
            'hashed_token',
            '2024-01-01 11:59:00', // Created 1 minute ago
        ]);
        $hasher->shouldReceive('check')->with('plain_token', 'hashed_token')->andReturnTrue();

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600);

        $this->assertTrue($repository->exists($user, 'plain_token'));

        Carbon::setTestNow();
    }

    public function testExistsReturnsFalseForExpiredToken()
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 1, 12, 0, 0));

        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $cache->shouldReceive('get')->once()->andReturn([
            'hashed_token',
            '2024-01-01 10:00:00', // Created 2 hours ago, expired with 3600s TTL
        ]);

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600);

        $this->assertFalse($repository->exists($user, 'plain_token'));

        Carbon::setTestNow();
    }

    public function testExistsReturnsFalseForInvalidHash()
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 1, 12, 0, 0));

        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $cache->shouldReceive('get')->once()->andReturn([
            'hashed_token',
            '2024-01-01 11:59:00',
        ]);
        $hasher->shouldReceive('check')->with('wrong_token', 'hashed_token')->andReturnFalse();

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600);

        $this->assertFalse($repository->exists($user, 'wrong_token'));

        Carbon::setTestNow();
    }

    public function testRecentlyCreatedTokenReturnsTrueWithinThrottle()
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 1, 12, 0, 0));

        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $cache->shouldReceive('get')->once()->andReturn([
            'hashed_token',
            '2024-01-01 11:59:30', // Created 30 seconds ago
        ]);

        // Throttle of 60 seconds — 30 seconds ago is within throttle
        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600, 60);

        $this->assertTrue($repository->recentlyCreatedToken($user));

        Carbon::setTestNow();
    }

    public function testRecentlyCreatedTokenReturnsFalseAfterThrottle()
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 1, 12, 0, 0));

        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $cache->shouldReceive('get')->once()->andReturn([
            'hashed_token',
            '2024-01-01 11:58:00', // Created 2 minutes ago
        ]);

        // Throttle of 60 seconds — 2 minutes ago is past throttle
        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600, 60);

        $this->assertFalse($repository->recentlyCreatedToken($user));

        Carbon::setTestNow();
    }

    public function testRecentlyCreatedTokenReturnsFalseWhenThrottleIsZero()
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 1, 12, 0, 0));

        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $cache->shouldReceive('get')->once()->andReturn([
            'hashed_token',
            '2024-01-01 11:59:59', // Created 1 second ago
        ]);

        // Throttle of 0 — should always return false
        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600, 0);

        $this->assertFalse($repository->recentlyCreatedToken($user));

        Carbon::setTestNow();
    }

    public function testDeleteRemovesFromCache()
    {
        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $expectedKey = hash('sha256', 'foo@bar.com');
        $cache->shouldReceive('forget')->with($expectedKey)->once();

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key');
        $repository->delete($user);
    }

    public function testCacheKeyHashesEmail()
    {
        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);
        $user = m::mock(CanResetPassword::class);
        $user->shouldReceive('getEmailForPasswordReset')->andReturn('foo@bar.com');

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key');

        $this->assertSame(hash('sha256', 'foo@bar.com'), $repository->cacheKey($user));
    }

    public function testDeleteExpiredIsNoOp()
    {
        $cache = m::mock(Repository::class);
        $hasher = m::mock(Hasher::class);

        // No cache interaction expected — TTL handles expiry
        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key');
        $repository->deleteExpired();

        // If we get here without exceptions, it's a no-op as expected
        $this->assertTrue(true);
    }
}
