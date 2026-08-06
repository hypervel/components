<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Http\Request;
use Hypervel\Routing\Middleware\ThrottleRequestsWithRedis;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\RateLimiter;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Throwable;

class ThrottleRequestsWithRedisTest extends TestCase
{
    use InteractsWithRedis;

    public function testLockOpensImmediatelyAfterDecay(): void
    {
        $now = CarbonImmutable::now();

        CarbonImmutable::setTestNow($now);

        Route::get('/', function () {
            return 'yes';
        })->middleware(ThrottleRequestsWithRedis::class . ':2,1');

        $response = $this->withoutExceptionHandling()->get('/');
        $this->assertSame('yes', $response->getContent());
        $this->assertEquals(2, $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals(1, $response->headers->get('X-RateLimit-Remaining'));

        $response = $this->withoutExceptionHandling()->get('/');
        $this->assertSame('yes', $response->getContent());
        $this->assertEquals(2, $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals(0, $response->headers->get('X-RateLimit-Remaining'));

        CarbonImmutable::setTestNow($finish = $now->addSeconds(58));

        try {
            $this->withoutExceptionHandling()->get('/');
        } catch (Throwable $e) {
            $this->assertEquals(429, $e->getStatusCode());
            $this->assertEquals(2, $e->getHeaders()['X-RateLimit-Limit']);
            $this->assertEquals(0, $e->getHeaders()['X-RateLimit-Remaining']);
            // $this->assertTrue(in_array($e->getHeaders()['Retry-After'], [2, 3]));
            // $this->assertTrue(in_array($e->getHeaders()['X-RateLimit-Reset'], [$finish->getTimestamp() + 2, $finish->getTimestamp() + 3]));
        }
    }

    public function testItCanThrottleBasedOnResponse(): void
    {
        RateLimiter::for('throttle-not-found', function (Request $request) {
            return Limit::perMinute(1)->after(fn ($response) => $response->status() === 404);
        });

        Route::get('/', fn () => match (request('status')) {
            '404' => abort(404),
            default => 'ok',
        })->middleware(ThrottleRequestsWithRedis::using('throttle-not-found'));

        $this->get('?status=200')->assertOk()->assertHeader('X-RateLimit-Remaining', 1);
        $this->get('?status=200')->assertOk()->assertHeader('X-RateLimit-Remaining', 1);
        $this->get('?status=200')->assertOk()->assertHeader('X-RateLimit-Remaining', 1);

        $this->get('?status=404')->assertNotFound()->assertHeader('X-RateLimit-Remaining', 0);

        $this->get('?status=200')->assertTooManyRequests();
        $this->get('?status=404')->assertTooManyRequests();
    }

    public function testItReturnsConfiguredResponseWhenUsingAfterLimit(): void
    {
        RateLimiter::for('throttle-not-found', function (Request $request) {
            return Limit::perMinute(1)
                ->after(fn ($response) => $response->status() === 404)
                ->response(fn () => response('ah ah ah', status: 429));
        });

        Route::get('/', fn () => abort(404))
            ->middleware(ThrottleRequestsWithRedis::using('throttle-not-found'));

        $this->get('/')->assertNotFound();
        $this->get('/')->assertTooManyRequests()->assertContent('ah ah ah');
    }
}
