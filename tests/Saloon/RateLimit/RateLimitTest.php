<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit;

use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\RateLimit\Exceptions\RateLimitReachedException;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

class RateLimitTest extends TestCase
{
    public function testConnectorAndRequestPoliciesAreConsumedInOrder(): void
    {
        [$manager, $limiter, $http] = $this->manager();
        $http->fake(['*' => Factory::response(['ok' => true])]);
        $connector = new RateLimitedConnectorStub($manager);
        $request = new RateLimitedRequestStub;

        $response = $connector->send($request);

        $this->assertTrue($response->successful());
        $this->assertSame(1, $limiter->inspect(
            $connector->policy(),
            'saloon:' . $connector::class,
        )->remaining());
        $this->assertSame(1, $limiter->inspect(
            $request->policy(),
            'saloon:' . $request::class,
        )->remaining());
    }

    public function testDeniedPolicyThrowsItsDecisionBeforeTransport(): void
    {
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => Factory::response(['ok' => true])]);
        $connector = new RateLimitedConnectorStub($manager, maximumAttempts: 1);

        $connector->send(new PlainRateLimitRequestStub);

        try {
            $connector->send(new PlainRateLimitRequestStub);
            $this->fail('A denied rate limit reached transport.');
        } catch (RateLimitReachedException $exception) {
            $this->assertInstanceOf(Limit::class, $exception->policy());
            $this->assertSame('connector', $exception->policy()->key);
            $this->assertTrue($exception->result()->denied());
        }

        $http->assertSentCount(1);
    }

    public function testFakesAndCacheHitsDoNotConsumeAdmissionCapacity(): void
    {
        [$manager, $limiter, $http] = $this->manager();
        $http->fake(['*' => Factory::response(['network' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $policy = Limit::perMinute(2)->by('cache');
        $request = new CachedRateLimitedRequestStub($policy);

        $manager->fake([MockResponse::make(['fake' => true])]);
        $connector->send($request);
        $manager->clearFake();

        $this->assertSame(2, $limiter->inspect($policy, 'saloon:' . $request::class)->remaining());

        $network = $connector->send($request);
        $cached = $connector->send($request);

        $this->assertFalse($network->isCached());
        $this->assertTrue($cached->isCached());
        $this->assertSame(1, $limiter->inspect($policy, 'saloon:' . $request::class)->remaining());
        $http->assertSentCount(1);
    }

    public function testPoliciesCanUseOperationStateAndSelectAStore(): void
    {
        [$manager, $limiter, $http, $rateLimiter] = $this->manager();
        $http->fake(['*' => Factory::response(['ok' => true])]);
        $rateLimiter->shouldReceive('store')->twice()->with('secondary')->andReturn($limiter);
        $connector = new PlainRateLimitConnectorStub($manager);
        $tenantA = (new OperationRateLimitRequestStub)->withHeader('X-Tenant', 'tenant-a');
        $tenantB = (new OperationRateLimitRequestStub)->withHeader('X-Tenant', 'tenant-b');

        $connector->send($tenantA);
        $connector->send($tenantB);

        $this->assertSame(1, $limiter->inspect(
            Limit::perMinute(2)->by('tenant-a'),
            'saloon:' . $tenantA::class,
        )->remaining());
        $this->assertSame(1, $limiter->inspect(
            Limit::perMinute(2)->by('tenant-b'),
            'saloon:' . $tenantB::class,
        )->remaining());
    }

    public function testMultiplePoliciesRetainEarlierReservationsWhenALaterPolicyDenies(): void
    {
        [$manager, $limiter, $http] = $this->manager();
        $http->fake(['*' => Factory::response(['unexpected' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $request = new MultipleRateLimitRequestStub;
        $limiterName = 'saloon:' . $request::class;

        $limiter->consume($request->secondPolicy(), $limiterName);

        try {
            $connector->send($request);
            $this->fail('A denied second policy reached transport.');
        } catch (RateLimitReachedException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(9, $limiter->inspect($request->firstPolicy(), $limiterName)->remaining());
        $this->assertSame(0, $limiter->inspect($request->secondPolicy(), $limiterName)->remaining());
        $http->assertNothingSent();
    }

    public function testServerCooldownIsRecordedBeforeResponseMiddleware(): void
    {
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => $http->sequence()
            ->push([], 429, ['Retry-After' => '10'])
            ->push(['unexpected' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);

        try {
            $connector->send(new ThrowingCooldownRequestStub);
            $this->fail('The response middleware did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('response middleware failed', $exception->getMessage());
        }

        try {
            $connector->send(new ThrowingCooldownRequestStub);
            $this->fail('A recorded cooldown reached transport.');
        } catch (RateLimitReachedException $exception) {
            $this->assertSame(10, $exception->result()->retryAfter());
        }

        $http->assertSentCount(1);
    }

    public function testRetryAfterHttpDatesAreParsedAndInvalidValuesAreIgnored(): void
    {
        $now = CarbonImmutable::parse('2026-08-13 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => $http->sequence()
            ->push([], 429, ['Retry-After' => $now->addSeconds(5)->format(DateTimeInterface::RFC7231)])
            ->push([], 429, ['Retry-After' => 'invalid'])
            ->push(['ok' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $dated = new DateCooldownRequestStub;

        $connector->send($dated);

        try {
            $connector->send($dated);
            $this->fail('A date-based cooldown reached transport.');
        } catch (RateLimitReachedException $exception) {
            $this->assertSame(5, $exception->result()->retryAfter());
        }

        $invalid = new InvalidCooldownRequestStub;
        $this->assertSame(429, $connector->send($invalid)->status());
        $this->assertTrue($connector->send($invalid)->successful());
        $http->assertSentCount(3);
    }

    public function testObsoleteHttpDatesAndCheckedNumericDurationsAreParsed(): void
    {
        $now = CarbonImmutable::parse('2026-08-06 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => $http->sequence()
            ->push([], 429, ['Retry-After' => $now->addSeconds(5)->format('l, d-M-y H:i:s \G\M\T')])
            ->push([], 429, ['Retry-After' => 'Thu Aug  6 12:00:06 2026'])
            ->push([], 429, ['Retry-After' => 'Fri, 06 Aug 2026 12:00:07 GMT'])
            ->push([], 429, ['Retry-After' => '0008'])
            ->push([], 429, ['Retry-After' => str_repeat('9', 30)])
            ->push(['ok' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $assertCooldown = function (Request $request, int $seconds) use ($connector): void {
            $connector->send($request);

            try {
                $connector->send($request);
                $this->fail('A parsed cooldown reached transport.');
            } catch (RateLimitReachedException $exception) {
                $this->assertSame($seconds, $exception->result()->retryAfter());
            }
        };

        $assertCooldown(new Rfc850CooldownRequestStub, 5);
        $assertCooldown(new AsctimeCooldownRequestStub, 6);
        $assertCooldown(new MismatchedWeekdayCooldownRequestStub, 7);
        $assertCooldown(new NumericCooldownRequestStub, 8);
        $this->assertSame(429, $connector->send(new OversizedCooldownRequestStub)->status());
        $this->assertTrue($connector->send(new OversizedCooldownRequestStub)->successful());
        $http->assertSentCount(6);
    }

    public function testTwoDigitAsctimeDayIsParsed(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 12:00:00 UTC');
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => Factory::response(
            body: [],
            status: 429,
            headers: ['Retry-After' => 'Thu Aug 13 12:00:07 2026'],
        )]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $request = new TwoDigitAsctimeCooldownRequestStub;

        $connector->send($request);

        try {
            $connector->send($request);
            $this->fail('A two-digit asctime cooldown reached transport.');
        } catch (RateLimitReachedException $exception) {
            $this->assertSame(7, $exception->result()->retryAfter());
        }

        $http->assertSentCount(1);
    }

    public function testHttpDateProtocolRulesAndInvalidCalendarValuesAreHandled(): void
    {
        $now = CarbonImmutable::parse('2026-08-06 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => $http->sequence()
            ->push([], 429, ['Retry-After' => 'Sunday, 06-Nov-70 12:00:00 GMT'])
            ->push([], 429, ['Retry-After' => 'Thu, 06 Aug 2026 12:00:60 GMT'])
            ->push([], 429, ['Retry-After' => 'Thu, 31 Feb 2026 12:00:09 GMT'])
            ->push(['ok' => true])
            ->push([], 429, ['Retry-After' => 'Thu, 06 Aug 2026 25:00:09 GMT'])
            ->push(['ok' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $assertCooldown = function (Request $request, int $seconds) use ($connector): void {
            $connector->send($request);

            try {
                $connector->send($request);
                $this->fail('A parsed cooldown reached transport.');
            } catch (RateLimitReachedException $exception) {
                $this->assertSame($seconds, $exception->result()->retryAfter());
            }
        };

        $assertCooldown(
            new Rfc850FutureYearCooldownRequestStub,
            CarbonImmutable::parse('2070-11-06 12:00:00 UTC')->getTimestamp() - $now->getTimestamp(),
        );
        $assertCooldown(new LeapSecondCooldownRequestStub, 60);

        $invalid = new InvalidCalendarCooldownRequestStub;
        $this->assertSame(429, $connector->send($invalid)->status());
        $this->assertTrue($connector->send($invalid)->successful());

        $invalid = new InvalidTimeCooldownRequestStub;
        $this->assertSame(429, $connector->send($invalid)->status());
        $this->assertTrue($connector->send($invalid)->successful());
        $http->assertSentCount(6);
    }

    public function testRfc850YearResolutionUsesGmtAtACenturyBoundary(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');

        try {
            $now = CarbonImmutable::parse('2100-01-01 00:30:00 UTC');
            CarbonImmutable::setTestNow($now);
            [$manager, , $http] = $this->manager();
            $http->fake(['*' => Factory::response(
                body: [],
                status: 429,
                headers: ['Retry-After' => 'Sunday, 01-Jan-50 00:30:00 GMT'],
            )]);
            $connector = new PlainRateLimitConnectorStub($manager);
            $request = new Rfc850FutureYearCooldownRequestStub;

            $connector->send($request);

            try {
                $connector->send($request);
                $this->fail('An RFC 850 cooldown resolved against the wrong timezone.');
            } catch (RateLimitReachedException $exception) {
                $this->assertSame(
                    CarbonImmutable::parse('2150-01-01 00:30:00 UTC')->getTimestamp() - $now->getTimestamp(),
                    $exception->result()->retryAfter(),
                );
            }

            $http->assertSentCount(1);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testRfc850YearResolutionUsesTheCompleteFiftyYearBoundary(): void
    {
        $now = CarbonImmutable::parse('2026-08-06 12:00:00 UTC');
        CarbonImmutable::setTestNow($now);
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => $http->sequence()
            ->push([], 429, ['Retry-After' => 'Thursday, 31-Dec-76 12:00:00 GMT'])
            ->push(['ok' => true])
            ->push([], 429, ['Retry-After' => 'Thursday, 06-Aug-76 12:00:00 GMT'])
            ->push([], 429, ['Retry-After' => 'Thursday, 01-Jan-76 12:00:00 GMT'])
            ->push([], 429, ['Retry-After' => 'Thu, 31 Dec 2076 12:00:00 GMT'])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $assertCooldown = function (Request $request, string $availableAt) use ($connector, $now): void {
            $connector->send($request);

            try {
                $connector->send($request);
                $this->fail('A date-based cooldown reached transport.');
            } catch (RateLimitReachedException $exception) {
                $this->assertSame(
                    CarbonImmutable::parse($availableAt)->getTimestamp() - $now->getTimestamp(),
                    $exception->result()->retryAfter(),
                );
            }
        };

        $rolledBack = new Rfc850BeyondFiftyYearsCooldownRequestStub;
        $this->assertSame(429, $connector->send($rolledBack)->status());
        $this->assertTrue($connector->send($rolledBack)->successful());

        $assertCooldown(new Rfc850ExactFiftyYearsCooldownRequestStub, '2076-08-06 12:00:00 UTC');
        $assertCooldown(new Rfc850WithinFiftyYearsCooldownRequestStub, '2076-01-01 12:00:00 UTC');
        $assertCooldown(new ImfBeyondFiftyYearsCooldownRequestStub, '2076-12-31 12:00:00 UTC');
        $http->assertSentCount(5);
    }

    public function testWaitingUsesTheStoreDelayAndThenContinues(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 12:00:00 UTC');
        Sleep::fake(syncWithCarbon: true);
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => $http->sequence()
            ->push([], 429, ['Retry-After' => '2'])
            ->push(['ok' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $request = new WaitingCooldownRequestStub;

        $connector->send($request);
        $response = $connector->send($request);

        $this->assertTrue($response->successful());
        Sleep::assertSlept(static fn ($duration): bool => (float) $duration->totalSeconds === 2.0);
        $http->assertSentCount(2);
    }

    public function testServerOnlyPolicyCallbacksAreRejected(): void
    {
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => Factory::response(['unexpected' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);

        $this->expectException(InvalidArgumentException::class);

        $connector->send(new CallbackRateLimitRequestStub);
    }

    /**
     * Create a manager and isolated worker-array limiter.
     *
     * @return array{SaloonManager, Limiter, Factory, RateLimiter}
     */
    protected function manager(): array
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $cacheRepository = new Repository(new ArrayStore);
        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->with(null)->andReturn($cacheRepository)->byDefault();
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')->with('saloon.connection.name')->andReturn('saloon');
        $config->shouldReceive('get')->with('saloon.cache.store')->andReturn(null)->byDefault();
        $config->shouldReceive('get')->with('saloon.rate_limiter.store')->andReturn(null)->byDefault();
        $limiter = new Limiter(
            new WorkerArrayStore,
            new KeyResolver('saloon-tests', static fn (): ?string => null),
        );
        $rateLimiter = m::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('store')->with(null)->andReturn($limiter)->byDefault();
        $manager = new SaloonManager(
            new Sender($http, $config),
            $cache,
            $rateLimiter,
            $config,
            new Dispatcher,
        );

        return [$manager, $limiter, $http, $rateLimiter];
    }
}

class PlainRateLimitConnectorStub extends Connector
{
    public function __construct(protected SaloonManager $manager)
    {
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        return $this->manager->send($this, $request, $mockClient);
    }
}

class RateLimitedConnectorStub extends PlainRateLimitConnectorStub
{
    use HasRateLimits;

    public function __construct(
        SaloonManager $manager,
        protected int $maximumAttempts = 2,
    ) {
        parent::__construct($manager);
    }

    public function policy(): AdmissionPolicy
    {
        return Limit::perMinute($this->maximumAttempts)->by('connector');
    }

    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [$this->policy()];
    }
}

class PlainRateLimitRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/resource';
    }
}

class RateLimitedRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    public function policy(): AdmissionPolicy
    {
        return Limit::perMinute(2)->by('request');
    }

    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [$this->policy()];
    }
}

class CachedRateLimitedRequestStub extends PlainRateLimitRequestStub implements Cacheable
{
    use HasCaching;
    use HasRateLimits;

    public function __construct(protected AdmissionPolicy $policy)
    {
    }

    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 60;
    }

    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [$this->policy];
    }
}

class ThrowingCooldownRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->middleware()->onResponse(
            static fn (): never => throw new RuntimeException('response middleware failed'),
        );
    }

    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [];
    }
}

class DateCooldownRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [];
    }
}

class InvalidCooldownRequestStub extends DateCooldownRequestStub
{
}

class Rfc850CooldownRequestStub extends DateCooldownRequestStub
{
}

class AsctimeCooldownRequestStub extends DateCooldownRequestStub
{
}

class TwoDigitAsctimeCooldownRequestStub extends DateCooldownRequestStub
{
}

class MismatchedWeekdayCooldownRequestStub extends DateCooldownRequestStub
{
}

class Rfc850FutureYearCooldownRequestStub extends DateCooldownRequestStub
{
}

class Rfc850BeyondFiftyYearsCooldownRequestStub extends DateCooldownRequestStub
{
}

class Rfc850ExactFiftyYearsCooldownRequestStub extends DateCooldownRequestStub
{
}

class Rfc850WithinFiftyYearsCooldownRequestStub extends DateCooldownRequestStub
{
}

class ImfBeyondFiftyYearsCooldownRequestStub extends DateCooldownRequestStub
{
}

class LeapSecondCooldownRequestStub extends DateCooldownRequestStub
{
}

class InvalidCalendarCooldownRequestStub extends DateCooldownRequestStub
{
}

class InvalidTimeCooldownRequestStub extends DateCooldownRequestStub
{
}

class NumericCooldownRequestStub extends DateCooldownRequestStub
{
}

class OversizedCooldownRequestStub extends DateCooldownRequestStub
{
}

class WaitingCooldownRequestStub extends DateCooldownRequestStub
{
    protected function waitForRateLimits(): bool
    {
        return true;
    }
}

class CallbackRateLimitRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [Limit::perMinute(1)->after(static fn (): bool => true)];
    }
}

class OperationRateLimitRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [Limit::perMinute(2)->by((string) $pendingRequest->headers()['X-Tenant'])];
    }

    protected function resolveRateLimitStore(): string
    {
        return 'secondary';
    }
}

class MultipleRateLimitRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    public function firstPolicy(): AdmissionPolicy
    {
        return Limit::perMinute(10)->by('first');
    }

    public function secondPolicy(): AdmissionPolicy
    {
        return Limit::perMinute(1)->by('second');
    }

    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [$this->firstPolicy(), $this->secondPolicy()];
    }
}
