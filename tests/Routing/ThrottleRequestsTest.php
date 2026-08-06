<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\ThrottleRequestsTest;

use Hypervel\Auth\GenericUser;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\GlobalLimit;
use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Http\Request;
use Hypervel\Redis\RedisProxy;
use Hypervel\Routing\Middleware\ThrottleRequests;
use Hypervel\Routing\Middleware\ThrottleRequestsWithRedis;
use Hypervel\Tests\Routing\RoutingTestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRequestsTest extends RoutingTestCase
{
    public function testAuthenticatedIntegerIdentifierIsNormalizedForRequestSignature(): void
    {
        $request = Request::create('/ping');
        $request->setUserResolver(fn (): GenericUser => new GenericUser([
            'id' => 123,
            'password' => 'secret',
            'remember_token' => null,
        ]));

        $this->assertSame(
            hash('xxh128', '123'),
            (new ExposesThrottleRequestSignature)->resolveRequestSignatureForTest($request)
        );

        ThrottleRequests::shouldHashKeys(false);

        $this->assertSame(
            '123',
            (new ExposesThrottleRequestSignature)->resolveRequestSignatureForTest($request)
        );
    }

    public function testNamedLimiterUsesCentralizedScopedHash(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $limiter->for('uploads', fn () => Limit::perMinute(10)->by('user-1'));
        $limiter->resolveKeyScopeUsing(fn () => 'account-1');

        (new ThrottleRequests($limiter))->handle(
            Request::create('/upload'),
            fn () => new Response('ok'),
            'uploads',
        );

        $this->assertSame(
            1,
            $limiter->attempts(hash('xxh128', '9:account-17:uploads6:user-1')),
        );
    }

    public function testNamedLimiterUsesCentralizedRawScopedKeyWhenHashingIsDisabled(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $limiter->for('uploads', fn () => Limit::perMinute(10)->by('user-1'));
        $limiter->resolveKeyScopeUsing(fn () => 'account-1');
        ThrottleRequests::shouldHashKeys(false);

        (new ThrottleRequests($limiter))->handle(
            Request::create('/upload'),
            fn () => new Response('ok'),
            'uploads',
        );

        $this->assertSame(1, $limiter->attempts('9:account-17:uploads6:user-1'));
    }

    public function testGlobalNamedLimiterDoesNotResolveScope(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $limiter->for('uploads', fn () => new GlobalLimit(10));
        $limiter->resolveKeyScopeUsing(function (): never {
            throw new RuntimeException('Scope resolver should not run.');
        });

        (new ThrottleRequests($limiter))->handle(
            Request::create('/upload'),
            fn () => new Response('ok'),
            'uploads',
        );

        $this->assertSame(1, $limiter->attempts(hash('xxh128', '7:uploads0:')));
    }

    public function testUnlimitedNamedLimiterBypassesStorage(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $limiter->for('uploads', fn () => Limit::none());
        $nextCalls = 0;

        (new ThrottleRequests($limiter))->handle(
            Request::create('/upload'),
            function () use (&$nextCalls): Response {
                ++$nextCalls;

                return new Response('ok');
            },
            'uploads',
        );

        $this->assertSame(1, $nextCalls);
        $this->assertSame(0, $limiter->attempts(hash('xxh128', '7:uploads0:')));
    }

    public function testDefaultSignatureThrottleDoesNotResolveNamedLimiterScope(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $scopeCalls = 0;
        $limiter->resolveKeyScopeUsing(function () use (&$scopeCalls): string {
            ++$scopeCalls;

            return 'account-1';
        });

        $request = Request::create('/upload');
        $request->setUserResolver(fn (): GenericUser => new GenericUser([
            'id' => 123,
            'password' => 'secret',
            'remember_token' => null,
        ]));

        (new ThrottleRequests($limiter))->handle(
            $request,
            fn () => new Response('ok'),
            10,
            1,
        );

        $this->assertSame(0, $scopeCalls);
        $this->assertSame(1, $limiter->attempts(hash('xxh128', '123')));
    }

    public function testRedisThrottleUsesOneAtomicAcquireForOrdinaryLimits(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('eval')->once()->andReturn([1, time() + 60, 1]);

        $response = $this->redisThrottle(Limit::perMinute(2), $connection)->handle(
            Request::create('/upload'),
            fn () => new Response('ok'),
            'uploads',
        );

        $this->assertSame('1', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testRedisThrottleUsesOnePrecheckForAnExcludedResponse(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('eval')->once()->andReturn([time() + 60, 2]);

        $response = $this->redisThrottle(
            Limit::perMinute(2)->after(fn (Response $response): bool => $response->getStatusCode() === 404),
            $connection,
        )->handle(
            Request::create('/upload'),
            fn () => new Response('ok'),
            'uploads',
        );

        $this->assertSame('2', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testRedisThrottleUsesAPrecheckAndAcquireForAQualifyingResponse(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('eval')
            ->twice()
            ->andReturn([time() + 60, 2], [1, time() + 60, 1]);

        $response = $this->redisThrottle(
            Limit::perMinute(2)->after(fn (Response $response): bool => $response->getStatusCode() === 404),
            $connection,
        )->handle(
            Request::create('/upload'),
            fn () => new Response('not found', 404),
            'uploads',
        );

        $this->assertSame('1', $response->headers->get('X-RateLimit-Remaining'));
    }

    private function redisThrottle(Limit $limit, RedisProxy $connection): ThrottleRequestsWithRedis
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $limiter->for('uploads', fn () => $limit);

        $redis = m::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->andReturn($connection);

        return new ThrottleRequestsWithRedis($limiter, $redis);
    }
}

class ExposesThrottleRequestSignature extends ThrottleRequests
{
    public function __construct()
    {
    }

    public function resolveRequestSignatureForTest(Request $request): string
    {
        return $this->resolveRequestSignature($request);
    }
}
