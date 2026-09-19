<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Auth\GenericUser;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\User;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\BackoffResult;
use Hypervel\RateLimiter\Contracts\Store;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\RateLimiter\CooldownResult;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Routing\Exceptions\MissingRateLimiterException;
use Hypervel\Routing\Middleware\ThrottleRequests;
use Hypervel\Routing\Route as RoutingRoute;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRequestsTest extends TestCase
{
    public function testInlineLimitOpensImmediatelyAfterDecay(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::class . ':2,1');

        $this->get('/')
            ->assertOk()
            ->assertContent('yes')
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 1);

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', 0);

        CarbonImmutable::setTestNow('2000-01-01 00:00:58');

        $this->get('/')
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 0)
            ->assertHeader('Retry-After', 2)
            ->assertHeader('X-RateLimit-Reset', CarbonImmutable::now()->addSeconds(2)->getTimestamp());

        CarbonImmutable::setTestNow('2000-01-01 00:01:00');

        $this->get('/')->assertOk();
    }

    public function testNamedLimiterUsesItsRegisteredStore(): void
    {
        config([
            'rate-limiter.stores.routes' => [
                'driver' => 'worker-array',
            ],
        ]);

        $manager = $this->app->make(RateLimiter::class);
        $policy = Limit::perMinute(1)->by('uploads');
        $manager->for('uploads', fn (): Limit => $policy, store: 'routes');

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('uploads'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 1)
            ->assertHeader('X-RateLimit-Remaining', 0);

        $this->assertTrue($manager->store('routes')->inspect($policy, 'uploads')->denied());
        $this->assertTrue($manager->store()->inspect($policy, 'uploads')->allowed());
    }

    public function testNamedLimiterScopeSeparatesPoliciesUnlessTheyAreGlobal(): void
    {
        $scope = 'tenant-a';
        $manager = $this->app->make(RateLimiter::class);
        $manager->resolveKeyScopeUsing(static function () use (&$scope): string {
            return $scope;
        });
        $manager->for('scoped', fn () => Limit::perMinute(1)->by('api'));
        $manager->for('global', fn () => Limit::perMinute(1)->by('api')->globally());

        Route::get('/scoped', fn (): string => 'yes')->middleware(ThrottleRequests::using('scoped'));
        Route::get('/global', fn (): string => 'yes')->middleware(ThrottleRequests::using('global'));

        $this->get('/scoped')->assertOk();
        $this->get('/scoped')->assertTooManyRequests();
        $this->get('/global')->assertOk();

        $scope = 'tenant-b';

        $this->get('/scoped')->assertOk();
        $this->get('/global')->assertTooManyRequests();
    }

    public function testMissingNamedLimiterThrowsTheLaravelException(): void
    {
        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('missing'));

        $this->expectExceptionObject(new MissingRateLimiterException('Rate limiter [missing] is not defined.'));

        $this->withoutExceptionHandling()->get('/');
    }

    public function testItFailsIfNamedLimiterDoesNotExistAndAuthenticatedUserDoesNotHaveFallbackProperty(): void
    {
        $this->expectExceptionObject(new MissingRateLimiterException('Rate limiter [' . User::class . '::rateLimiting] is not defined.'));

        Route::get('/', fn (): string => 'ok')->middleware(['auth', ThrottleRequests::using('rateLimiting')]);

        // Strict mode on a hydrated model ensures this reports the missing limiter
        // without trying to read a model attribute that does not exist.
        Model::shouldBeStrict();
        $user = (new User)->newFromBuilder([
            'id' => 1,
            'name' => 'Mateus',
            'email' => 'mateus@example.org',
            'password' => 'password',
        ]);

        $this->withoutExceptionHandling()->actingAs($user)->get('/');
    }

    public function testInlineLimitSelectsGuestAuthenticatedAndUserAttributeCapacities(): void
    {
        $middleware = new ThrottleRequests($this->app->make(RateLimiter::class));
        $guestRequest = Request::create('/guest');
        $guestRequest->setRouteResolver(fn () => new RoutingRoute('GET', '/guest', fn () => null));

        $guestResponse = $middleware->handle(
            $guestRequest,
            fn (): Response => new Response('guest'),
            '2|3',
        );

        $user = new ThrottleRequestsUser([
            'id' => 1,
            'password' => 'secret',
            'remember_token' => null,
            'rateLimiting' => 1,
        ]);
        $userRequest = Request::create('/user');
        $userRequest->setRouteResolver(fn () => new RoutingRoute('GET', '/user', fn () => null));
        $userRequest->setUserResolver(fn (): ThrottleRequestsUser => $user);

        $authenticatedResponse = $middleware->handle(
            $userRequest,
            fn (): Response => new Response('user'),
            '2|3',
        );
        $attributeResponse = $middleware->handle(
            $userRequest,
            fn (): Response => new Response('user'),
            'rateLimiting',
        );

        $this->assertSame('2', $guestResponse->headers->get('X-RateLimit-Limit'));
        $this->assertSame('3', $authenticatedResponse->headers->get('X-RateLimit-Limit'));
        $this->assertSame('1', $attributeResponse->headers->get('X-RateLimit-Limit'));
    }

    public function testNamedLimiterMayReturnAResponseOrUnlimitedPolicy(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('response', fn (): Response => new Response('limited elsewhere', 409));
        $manager->for('unlimited', fn () => Limit::none());

        Route::get('/response', fn (): string => 'route')->middleware(ThrottleRequests::using('response'));
        Route::get('/unlimited', fn (): string => 'route')->middleware(ThrottleRequests::using('unlimited'));

        $this->get('/response')->assertStatus(409)->assertContent('limited elsewhere');
        $this->get('/unlimited')
            ->assertOk()
            ->assertContent('route')
            ->assertHeaderMissing('X-RateLimit-Limit');
    }

    // REMOVED: Laravel's zero-remaining retry fallback is replaced by the
    // unused capacity returned by the atomic weighted decision.

    public function testWeightedDenialReportsTruthfulRemainingCapacity(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('uploads', fn () => Limit::perMinute(5)->cost(3)->by('uploads'));

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('uploads'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 5)
            ->assertHeader('X-RateLimit-Remaining', 2);

        $this->get('/')
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', 5)
            ->assertHeader('X-RateLimit-Remaining', 2);
    }

    public function testLeakyBucketHeadersDescribeBurstCapacity(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('api', fn () => LeakyBucket::perMinute(2)->burst(4)->cost(2)->by('api'));

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('api'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 4)
            ->assertHeader('X-RateLimit-Remaining', 2);

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', 0);

        $this->get('/')
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', 4)
            ->assertHeader('X-RateLimit-Remaining', 0);
    }

    public function testSlidingWindowHeadersUseItsCapacityAndRetryDelay(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('api', fn () => SlidingWindow::perSecond(2, 2)->by('api'));

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('api'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 1);

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', 0);

        $this->get('/')
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 0)
            ->assertHeader('Retry-After', 3)
            ->assertHeader('X-RateLimit-Reset', CarbonImmutable::now()->addSeconds(3)->getTimestamp());
    }

    // REMOVED: Laravel's shouldHashKeys(false) coverage does not apply because
    // canonical rate-limiter identities are always hashed.

    public function testResponseBasedLimitConsumesOnlyMatchingResponses(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('not-found', fn () => Limit::perMinute(1)
            ->by('not-found')
            ->after(fn (Response $response): bool => $response->getStatusCode() === 404));

        Route::get('/', fn (Request $request) => $request->query('missing') === 'yes'
            ? new Response('missing', 404)
            : new Response('ok'))
            ->middleware(ThrottleRequests::using('not-found'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', 1);
        $this->get('/?missing=yes')
            ->assertNotFound()
            ->assertHeader('X-RateLimit-Remaining', 0);
        $this->get('/')->assertTooManyRequests();
    }

    public function testResponseBasedLimitUsesItsConfiguredResponse(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('not-found', fn () => Limit::perMinute(1)
            ->by('not-found')
            ->after(fn (Response $response): bool => $response->getStatusCode() === 404)
            ->response(fn (): Response => new Response('ah ah ah', 429)));

        Route::get('/', fn (): Response => new Response('missing', 404))
            ->middleware(ThrottleRequests::using('not-found'));

        $this->get('/')->assertNotFound();
        $this->get('/')->assertTooManyRequests()->assertContent('ah ah ah');
    }

    public function testOrdinaryLimitUsesOneAtomicConsumeWithoutInspection(): void
    {
        $store = $this->countingStoreFor('uploads', Limit::perMinute(2)->by('uploads'));

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('uploads'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', 1);

        $this->assertSame(1, $store->consumeCalls);
        $this->assertSame(0, $store->inspectCalls);
    }

    public function testExcludedResponseUsesOneInspectionWithoutConsumption(): void
    {
        $store = $this->countingStoreFor(
            'not-found',
            Limit::perMinute(2)
                ->by('not-found')
                ->after(fn (Response $response): bool => $response->getStatusCode() === 404),
        );

        Route::get('/', fn (): Response => new Response('ok'))
            ->middleware(ThrottleRequests::using('not-found'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', 2);

        $this->assertSame(0, $store->consumeCalls);
        $this->assertSame(1, $store->inspectCalls);
    }

    public function testQualifyingResponseUsesOneInspectionAndOneAtomicConsume(): void
    {
        $store = $this->countingStoreFor(
            'not-found',
            Limit::perMinute(2)
                ->by('not-found')
                ->after(fn (Response $response): bool => $response->getStatusCode() === 404),
        );

        Route::get('/', fn (): Response => new Response('missing', 404))
            ->middleware(ThrottleRequests::using('not-found'));

        $this->get('/')
            ->assertNotFound()
            ->assertHeader('X-RateLimit-Remaining', 1);

        $this->assertSame(1, $store->consumeCalls);
        $this->assertSame(1, $store->inspectCalls);
    }

    public function testConcurrentPostResponseDenialDoesNotReplaceTheAdmittedResponse(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $policy = Limit::perMinute(1)->by('race');

        $manager->for('race', fn () => $policy->after(function () use ($manager, $policy): bool {
            $manager->store()->consume($policy, 'race');

            return true;
        }));

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('race'));

        $this->get('/')
            ->assertOk()
            ->assertContent('yes')
            ->assertHeader('X-RateLimit-Limit', 1)
            ->assertHeader('X-RateLimit-Remaining', 0)
            ->assertHeaderMissing('Retry-After');
    }

    public function testMultipleDistinctKeysDoNotOverThrottle(): void
    {
        $rateLimiter = Container::getInstance()->make(RateLimiter::class);
        $rateLimiter->for('test', fn (): array => [
            Limit::perMinute(3)->by('minute-key'),
            Limit::perSecond(1)->by('second-key'),
            Limit::perDay(4)->by('day-key'),
        ]);
        Route::get('/', fn (): string => 'ok')->middleware(ThrottleRequests::using('test'));

        // Running 2 requests in a single second is not allowed.
        CarbonImmutable::setTestNow('2000-01-01 00:00:00.000');
        $this->get('/')->assertOk();
        $this->get('/')->assertStatus(429);
        $this->assertSame(2, $rateLimiter->inspect(Limit::perMinute(3)->by('minute-key'), 'test')->remaining());
        $this->assertSame(0, $rateLimiter->inspect(Limit::perSecond(1)->by('second-key'), 'test')->remaining());

        // After a second, the per-second limit resets.
        CarbonImmutable::setTestNow('2000-01-01 00:00:01.000');
        $this->get('/')->assertOk();
        $this->get('/')->assertStatus(429);

        // A third request is allowed in the same minute.
        CarbonImmutable::setTestNow('2000-01-01 00:00:02.000');
        $this->get('/')->assertOk();

        // A fourth request is not allowed in the same minute.
        CarbonImmutable::setTestNow('2000-01-01 00:00:03.000');
        $this->get('/')->assertStatus(429);

        // A fourth request is allowed in the same day, but not a fifth.
        CarbonImmutable::setTestNow('2000-01-01 01:00:00.000');
        $this->get('/')->assertOk();
        $this->get('/')->assertStatus(429);

        // The next day, all limits should reset.
        CarbonImmutable::setTestNow('2000-01-02 00:01:00.000');
        $this->get('/')->assertOk();
        CarbonImmutable::setTestNow('2000-01-02 00:01:01.000');
        $this->get('/')->assertOk();
        CarbonImmutable::setTestNow('2000-01-02 00:01:02.000');
        $this->get('/')->assertOk();
        CarbonImmutable::setTestNow('2000-01-02 00:01:03.000');
        $this->get('/')->assertStatus(429);
        CarbonImmutable::setTestNow('2000-01-02 01:00:00.000');
        $this->get('/')->assertOk();
        $this->get('/')->assertStatus(429);
    }

    public function testLimitOrderDoesNotAffectBehavior(): void
    {
        $rateLimiter = Container::getInstance()->make(RateLimiter::class);
        $rateLimiter->for('test', fn (): array => [
            Limit::perDay(4)->by('day-key'),
            Limit::perMinute(3)->by('minute-key'),
        ]);
        Route::get('/', fn (): string => 'ok')->middleware(ThrottleRequests::using('test'));

        CarbonImmutable::setTestNow('2000-01-01 00:00:00.000');

        // Make 3 requests, each a second apart, that should all be successful.
        for ($i = 0; $i < 3; ++$i) {
            $this->get('/')->assertOk();
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        }

        $this->assertSame('2000-01-01 00:00:03.000', CarbonImmutable::now()->toDateTimeString('m'));
        $this->get('/')->assertStatus(429);

        // A fourth request is allowed in the same day but not a fifth.
        CarbonImmutable::setTestNow('2000-01-01 00:01:00.000');
        $this->get('/')->assertOk();
        $this->get('/')->assertStatus(429);

        // After a day, both limits should reset.
        CarbonImmutable::setTestNow('2000-01-02 00:00:00.000');
        $this->get('/')->assertOk();
    }

    public function testDeferredDenialDoesNotChargeOrdinaryPoliciesAndPreservesDenialOrder(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $first = Limit::perMinute(2)->by('first')
            ->response(fn (): Response => new Response('first', 429));
        $second = Limit::perMinute(1)->by('second')
            ->after(fn (): bool => true)
            ->response(fn (): Response => new Response('second', 429));
        $manager->for('mixed', fn (): array => [$first, $second]);
        $manager->consume($second, 'mixed');

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('mixed'));

        $this->get('/')->assertTooManyRequests()->assertContent('second');
        $this->assertSame(2, $manager->inspect($first, 'mixed')->remaining());

        $manager->consume($first->cost(2), 'mixed');
        $this->get('/')->assertTooManyRequests()->assertContent('first');
    }

    public function testMixedPoliciesKeepHeaderOrderAndChargeOnlyMatchingResponses(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $deferred = Limit::perMinute(2)->by('deferred')->after(fn (): bool => true);
        $ordinary = Limit::perMinute(2)->by('ordinary');
        $manager->for('mixed', fn (): array => ['deferred' => $deferred, 'ordinary' => $ordinary]);

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('mixed'));

        $this->get('/')->assertOk()->assertHeader('X-RateLimit-Remaining', 1);
        $this->assertSame(1, $manager->inspect($deferred, 'mixed')->remaining());
        $this->assertSame(1, $manager->inspect($ordinary, 'mixed')->remaining());

        $manager->consume($ordinary, 'mixed');
        $this->get('/')->assertTooManyRequests()->assertHeader('X-RateLimit-Remaining', 0);
        $this->assertSame(1, $manager->inspect($deferred, 'mixed')->remaining());
    }

    public function testNestedSameKeyRequestsRetainTheirOwnDecisionHeaders(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('nested', fn () => Limit::perMinute(3)->by('nested'));
        $middleware = new ThrottleRequests($manager);
        $innerResponse = null;

        $outerResponse = $middleware->handle(
            Request::create('/outer'),
            function () use ($middleware, &$innerResponse): Response {
                $innerResponse = $middleware->handle(
                    Request::create('/inner'),
                    fn (): Response => new Response('inner'),
                    'nested',
                );

                return new Response('outer');
            },
            'nested',
        );

        $this->assertInstanceOf(Response::class, $innerResponse);
        $this->assertSame('1', $innerResponse->headers->get('X-RateLimit-Remaining'));
        $this->assertSame('2', $outerResponse->headers->get('X-RateLimit-Remaining'));
    }

    public function testCustomResponseReceivesLocalDecisionHeaders(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('custom', fn () => Limit::perMinute(1)
            ->by('custom')
            ->response(fn (Request $request, array $headers): Response => new Response(
                $request->path(),
                429,
                $headers,
            )));

        Route::get('/custom', fn (): string => 'yes')->middleware(ThrottleRequests::using('custom'));

        $this->get('/custom')->assertOk();
        $this->get('/custom')
            ->assertTooManyRequests()
            ->assertContent('custom')
            ->assertHeader('X-RateLimit-Limit', 1)
            ->assertHeader('X-RateLimit-Remaining', 0);
    }

    public function testApplicationProvidedLowerRemainingHeaderIsPreserved(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $manager->for('headers', fn () => Limit::perMinute(5)->by('headers'));

        Route::get('/', fn (): Response => new Response('yes', headers: [
            'X-RateLimit-Limit' => 1,
            'X-RateLimit-Remaining' => 0,
        ]))->middleware(ThrottleRequests::using('headers'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 1)
            ->assertHeader('X-RateLimit-Remaining', 0);
    }

    private function countingStoreFor(string $name, AdmissionPolicy $policy): ThrottleRequestsCountingStore
    {
        config([
            'rate-limiter.stores.counting' => [
                'driver' => 'counting',
            ],
        ]);

        $store = new ThrottleRequestsCountingStore(new WorkerArrayStore);
        $manager = $this->app->make(RateLimiter::class);
        $manager->extend('counting', static fn (): Store => $store);
        $manager->for($name, static fn (): AdmissionPolicy => $policy, store: 'counting');

        return $store;
    }
}

class ThrottleRequestsCountingStore implements Store
{
    public int $consumeCalls = 0;

    public int $inspectCalls = 0;

    public function __construct(protected Store $store)
    {
    }

    public function consume(string $key, AdmissionPolicy $policy): LimitResult
    {
        ++$this->consumeCalls;

        return $this->store->consume($key, $policy);
    }

    public function consumeMany(array $policies): array
    {
        ++$this->consumeCalls;

        return $this->store->consumeMany($policies);
    }

    public function block(string $key, int $durationMicroseconds): CooldownResult
    {
        return $this->store->block($key, $durationMicroseconds);
    }

    public function inspect(
        string $key,
        AdmissionPolicy|Backoff|Cooldown $policy,
    ): LimitResult|BackoffResult|CooldownResult {
        ++$this->inspectCalls;

        return $this->store->inspect($key, $policy);
    }

    public function recordFailure(string $key, Backoff $backoff): BackoffResult
    {
        return $this->store->recordFailure($key, $backoff);
    }

    public function clear(string $key): bool
    {
        return $this->store->clear($key);
    }
}

class ThrottleRequestsUser extends GenericUser
{
    public function hasAttribute(string $key): bool
    {
        return isset($this->{$key});
    }
}
