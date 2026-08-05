<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Auth\GenericUser;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
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

        $this->expectException(MissingRateLimiterException::class);
        $this->expectExceptionMessage('Rate limiter [missing] is not defined.');

        $this->withoutExceptionHandling()->get('/');
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

    // REMOVED: Laravel's preflight-all-then-hit-all behavior is replaced by
    // sequential atomic policy consumption without rollback.

    public function testEarlierPoliciesRemainConsumedWhenALaterPolicyDenies(): void
    {
        $manager = $this->app->make(RateLimiter::class);
        $first = Limit::perMinute(2)->by('first');
        $second = Limit::perMinute(1)->by('second');
        $manager->for('stacked', fn (): array => [$first, $second]);
        $manager->store()->consume($second, 'stacked');

        Route::get('/', fn (): string => 'yes')->middleware(ThrottleRequests::using('stacked'));

        $this->get('/')->assertTooManyRequests();

        $this->assertSame(1, $manager->store()->inspect($first, 'stacked')->remaining());
        $this->assertSame(0, $manager->store()->inspect($second, 'stacked')->remaining());
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
}

class ThrottleRequestsUser extends GenericUser
{
    public function hasAttribute(string $key): bool
    {
        return isset($this->{$key});
    }
}
