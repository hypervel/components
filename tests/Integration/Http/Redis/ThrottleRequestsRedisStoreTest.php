<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Routing\Middleware\ThrottleRequests;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRequestsRedisStoreTest extends TestCase
{
    use InteractsWithRedis;

    public function testNamedLimiterUsesRedisWithoutDedicatedMiddleware(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('redis', fn () => Limit::perMinute(2)->by('route'), store: 'redis');

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('redis'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 1);
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', 0);
        $denied = $this->get('/')
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 0)
            ->assertHeader('Retry-After');

        $this->assertGreaterThan(0, (int) $denied->headers->get('Retry-After'));
    }

    public function testResponseBasedLimitUsesRedisInspectionAndConditionalConsumption(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('not-found', fn () => Limit::perMinute(1)
            ->by('not-found')
            ->after(fn (Response $response): bool => $response->getStatusCode() === 404), store: 'redis');

        Route::get('/', fn (Request $request) => $request->query('missing') === 'yes'
            ? new Response('missing', 404)
            : new Response('ok'))
            ->middleware(ThrottleRequests::using('not-found'));

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();
        $this->get('/?missing=yes')
            ->assertNotFound()
            ->assertHeader('X-RateLimit-Remaining', 0);
        $this->get('/')->assertTooManyRequests();
    }
}
