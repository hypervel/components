<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\CacheTokenRepository;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class CacheTokenRepositoryTest extends TestCase
{
    use VerifiesDoubles;

    public function testCreateStoresHashedTokenAndReturnsPlainToken(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2024, 1, 1, 12, 0, 0));

        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $hasher->expects('make')->resolves(fn ($token) => 'hashed_' . $token);
        $cache->expects('forget'); // delete() called first
        $cache->expects('put')->resolves(function ($key, $value, $ttl) {
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
    }

    public function testExistsReturnsTrueForValidToken(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2024, 1, 1, 12, 0, 0));

        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $cache->expects('get')->returns([
            'hashed_token',
            '2024-01-01 11:59:00', // Created 1 minute ago
        ]);
        $hasher->expects('check')->with('plain_token', 'hashed_token')->returns(true);

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600);

        $this->assertTrue($repository->exists($user, 'plain_token'));
    }

    public function testExistsReturnsFalseForExpiredToken(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2024, 1, 1, 12, 0, 0));

        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $hasher->expects('check')->never();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $cache->expects('get')->returns([
            'hashed_token',
            '2024-01-01 10:00:00', // Created 2 hours ago, expired with 3600s TTL
        ]);

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600);

        $this->assertFalse($repository->exists($user, 'plain_token'));
    }

    public function testExistsReturnsFalseForInvalidHash(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2024, 1, 1, 12, 0, 0));

        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $cache->expects('get')->returns([
            'hashed_token',
            '2024-01-01 11:59:00',
        ]);
        $hasher->expects('check')->with('wrong_token', 'hashed_token')->returns(false);

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600);

        $this->assertFalse($repository->exists($user, 'wrong_token'));
    }

    public function testRecentlyCreatedTokenReturnsTrueWithinThrottle(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2024, 1, 1, 12, 0, 0));

        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $cache->expects('get')->returns([
            'hashed_token',
            '2024-01-01 11:59:30', // Created 30 seconds ago
        ]);

        // Throttle of 60 seconds — 30 seconds ago is within throttle
        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600, 60);

        $this->assertTrue($repository->recentlyCreatedToken($user));
    }

    public function testRecentlyCreatedTokenReturnsFalseAfterThrottle(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2024, 1, 1, 12, 0, 0));

        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $cache->expects('get')->returns([
            'hashed_token',
            '2024-01-01 11:58:00', // Created 2 minutes ago
        ]);

        // Throttle of 60 seconds — 2 minutes ago is past throttle
        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600, 60);

        $this->assertFalse($repository->recentlyCreatedToken($user));
    }

    public function testRecentlyCreatedTokenReturnsFalseWhenThrottleIsZero(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2024, 1, 1, 12, 0, 0));

        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $cache->expects('get')->returns([
            'hashed_token',
            '2024-01-01 11:59:59', // Created 1 second ago
        ]);

        // Throttle of 0 — should always return false
        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key', 3600, 0);

        $this->assertFalse($repository->recentlyCreatedToken($user));
    }

    public function testDeleteRemovesFromCache(): void
    {
        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $expectedKey = hash('sha256', 'foo@bar.com');
        $cache->expects('forget')->with($expectedKey);

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key');
        $repository->delete($user);
    }

    public function testCacheKeyHashesEmail(): void
    {
        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();
        $user = Double::for(CanResetPassword::class)->strict();
        $user->allows('getEmailForPasswordReset')->returns('foo@bar.com');

        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key');

        $this->assertSame(hash('sha256', 'foo@bar.com'), $repository->cacheKey($user));
    }

    public function testDeleteExpiredIsNoOp(): void
    {
        $cache = Double::for(Repository::class)->strict();
        $hasher = Double::for(Hasher::class)->strict();

        // No cache interaction expected — TTL handles expiry
        $repository = new CacheTokenRepository($cache, $hasher, 'test-hash-key');
        $repository->deleteExpired();

        $cache->unused();
        $hasher->unused();
    }
}
