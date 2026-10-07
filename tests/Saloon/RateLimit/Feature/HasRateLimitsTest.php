<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\RateLimiter\Limit;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\Request\Statuses\InternalServerErrorException;
use Hypervel\Saloon\Exceptions\Request\Statuses\TooManyRequestsException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\RateLimit\Exceptions\RateLimitReachedException;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\BaseConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\CustomPrefixConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\CustomTooManyRequestsConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\DisabledTooManyRequestsConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\SleepTooManyRequestsConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests\LimitedRequest;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests\LimitedSoloRequest;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests\UserRequest;
use PHPUnit\Framework\Attributes\DataProvider;

// Limits are framework rate-limiter policies, so the cases inspect them with Saloon::inspectRateLimit() instead of
// reading upstream's store records. Saloon fakes send nothing and consume no capacity, so the transport is faked with
// Http::fake(). A resource's waitForRateLimits() replaces upstream's sleep() on each limit.
class HasRateLimitsTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Define the environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('saloon.rate_limiter.store', 'worker-array');
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        CarbonImmutable::setTestNow('2026-08-13 12:00:00 UTC');
    }

    public function testWhenMakingARequestWithTheHasRateLimitsTraitAddedItWillRecordTheHitsAndThrowExceptions(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new TestConnector([$limit]);

        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        // Let's start by making sure nothing has been recorded

        $this->assertSame(3, Saloon::inspectRateLimit($connector, $limit)->remaining());

        // We'll now send four requests. The first three will be fine, the fourth should throw an exception

        $responseA = $connector->send(new UserRequest);
        $this->assertSame(200, $responseA->status());

        $result = Saloon::inspectRateLimit($connector, $limit);
        $this->assertSame(2, $result->remaining());
        $this->assertSame(60, $result->resetAfter());

        // A successful response records no "too many attempts" cooldown

        $this->assertTrue(Saloon::inspectRateLimit($connector, Cooldown::for(null))->allowed());

        $responseB = $connector->send(new UserRequest);
        $this->assertSame(200, $responseB->status());
        $this->assertSame(1, Saloon::inspectRateLimit($connector, $limit)->remaining());

        $responseC = $connector->send(new UserRequest);
        $this->assertSame(200, $responseC->status());
        $this->assertSame(0, Saloon::inspectRateLimit($connector, $limit)->remaining());
        $this->assertTrue(Saloon::inspectRateLimit($connector, Cooldown::for(null))->allowed());

        // Finally, when we make the fourth request it should throw an exception

        $thrown = false;

        try {
            $connector->send(new UserRequest);
        } catch (RateLimitReachedException $exception) {
            $thrown = true;

            $this->assertSame(
                sprintf('The request rate limit [saloon:%s] has been reached.', TestConnector::class),
                $exception->getMessage(),
            );
            $this->assertSame($limit, $exception->policy());
            $this->assertSame(0, $exception->result()->remaining());
            $this->assertSame(60, $exception->result()->retryAfter());
        }

        $this->assertTrue($thrown);
        $this->assertTrue(Saloon::inspectRateLimit($connector, $limit)->denied());
        Http::assertSentCount(3);
    }

    public function testWhenMakingARequestWithTheHasRateLimitsTraitAddedItWillRecordTheHitsAndCanSleep(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $limit = Limit::perSecond(3, 5);
        $connector = new TestConnector([$limit], waitFor: [$limit]);

        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        // We'll now send four requests. The first three will be fine, the fourth should wait

        $responseA = $connector->send(new UserRequest);
        $this->assertSame(200, $responseA->status());

        $result = Saloon::inspectRateLimit($connector, $limit);
        $this->assertSame(2, $result->remaining());
        $this->assertSame(5, $result->resetAfter());

        $responseB = $connector->send(new UserRequest);
        $this->assertSame(200, $responseB->status());
        $this->assertSame(1, Saloon::inspectRateLimit($connector, $limit)->remaining());

        $responseC = $connector->send(new UserRequest);
        $this->assertSame(200, $responseC->status());
        $this->assertSame(0, Saloon::inspectRateLimit($connector, $limit)->remaining());

        // Now when we make this request, it should pause for 5 seconds and then start a new window

        $responseD = $connector->send(new UserRequest);

        $this->assertSame(200, $responseD->status());
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
        $this->assertSame(2, Saloon::inspectRateLimit($connector, $limit)->remaining());
        Http::assertSentCount(4);
    }

    // The 429 response is returned and its cooldown is recorded, so the next request throws instead of the 429 itself.
    public function testYouCanCreateALimiterThatListensFor429AndWillAutomaticallyBackOffForTheRetryAfterDuration(): void
    {
        $limit = Limit::perSecond(3, 5);
        $connector = new TestConnector([$limit], waitFor: [$limit]);
        $cooldown = Cooldown::for(null);

        $this->assertTrue(Saloon::inspectRateLimit($connector, $cooldown)->allowed());

        Http::fake(['*' => Http::sequence()
            ->push(['name' => 'Sam', 'status' => 'Success'])
            ->push(['name' => 'Gareth', 'status' => 'Success'])
            ->push(['status' => 'Too Many Requests'], 429, ['Retry-After' => '500'])]);

        $connector->send(new UserRequest);

        $this->assertTrue(Saloon::inspectRateLimit($connector, $cooldown)->allowed());

        $connector->send(new UserRequest);

        $this->assertTrue(Saloon::inspectRateLimit($connector, $cooldown)->allowed());
        $this->assertSame(429, $connector->send(new UserRequest)->status());

        $thrown = false;

        try {
            $connector->send(new UserRequest);
        } catch (RateLimitReachedException $exception) {
            $thrown = true;

            $this->assertSame(
                sprintf('The request rate limit [saloon:%s] has been reached.', TestConnector::class),
                $exception->getMessage(),
            );
            $this->assertInstanceOf(Cooldown::class, $exception->policy());
            $this->assertSame(500, $exception->result()->retryAfter());
        }

        $this->assertTrue($thrown);
        Http::assertSentCount(3);
    }

    #[DataProvider('unusableRetryAfterProvider')]
    public function testIfTheRetryAfterHeaderIsMissingOrCannotBeParsedThenTheDefaultRetryIs60Seconds(?string $retryAfter): void
    {
        $limit = Limit::perSecond(3, 5);
        $connector = new TestConnector([$limit], waitFor: [$limit]);

        Http::fake(['*' => Http::sequence()
            ->push(['name' => 'Sam', 'status' => 'Success'])
            ->push(['name' => 'Gareth', 'status' => 'Success'])
            ->push(['status' => 'Too Many Requests'], 429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter])]);

        $connector->send(new UserRequest);
        $connector->send(new UserRequest);
        $connector->send(new UserRequest);

        $thrown = false;

        try {
            $connector->send(new UserRequest);
        } catch (RateLimitReachedException $exception) {
            $thrown = true;

            $this->assertSame(
                sprintf('The request rate limit [saloon:%s] has been reached.', TestConnector::class),
                $exception->getMessage(),
            );
            $this->assertInstanceOf(Cooldown::class, $exception->policy());
            $this->assertSame(60, $exception->result()->retryAfter());
        }

        $this->assertTrue($thrown);
    }

    /**
     * Get Retry-After values that do not give a usable delay.
     */
    public static function unusableRetryAfterProvider(): array
    {
        return [
            'missing' => [null],
            'malformed' => ['not-working'],
            'not an HTTP date' => ['01/01/2023'],
        ];
    }

    #[DataProvider('elapsedRetryAfterProvider')]
    public function testAValidRetryAfterThatHasAlreadyPassedRecordsNoCooldown(string $retryAfter): void
    {
        $connector = new TestConnector([]);

        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'Too Many Requests'], 429, ['Retry-After' => $retryAfter])
            ->push(['name' => 'Sam'])]);

        $this->assertSame(429, $connector->send(new UserRequest)->status());
        $this->assertTrue(Saloon::inspectRateLimit($connector, Cooldown::for(null))->allowed());
        $this->assertSame(200, $connector->send(new UserRequest)->status());
    }

    /**
     * Get valid Retry-After values whose delay has already passed.
     */
    public static function elapsedRetryAfterProvider(): array
    {
        return [
            'zero seconds' => ['0'],
            'past date' => ['Wed, 12 Aug 2026 12:00:00 GMT'],
        ];
    }

    // No admission policies are declared, so only the response-driven cooldown can deny the fourth request.
    public function testYouCanCustomiseWhenTheTooManyRequestsLimitIsApplied(): void
    {
        $connector = new CustomTooManyRequestsConnector([]);

        Http::fake(['*' => Http::sequence()
            ->push(['name' => 'Sam'])
            ->push(['name' => 'Sam'])
            ->push(['error' => 'Too Many Attempts'])]);

        $connector->send(new UserRequest);
        $connector->send(new UserRequest);

        $this->assertSame(['error' => 'Too Many Attempts'], $connector->send(new UserRequest)->json());

        try {
            $connector->send(new UserRequest);
            $this->fail('The custom cooldown was not applied.');
        } catch (RateLimitReachedException $exception) {
            $this->assertSame(
                sprintf('The request rate limit [saloon:%s] has been reached.', CustomTooManyRequestsConnector::class),
                $exception->getMessage(),
            );
            $this->assertInstanceOf(Cooldown::class, $exception->policy());
            $this->assertSame(60, $exception->result()->retryAfter());
        }

        Http::assertSentCount(3);
    }

    public function testYouCanDisableThe429ErrorDetection(): void
    {
        $connector = new DisabledTooManyRequestsConnector([]);
        $cooldown = Cooldown::for(null);

        Http::fake(['*' => Http::sequence()
            ->push(['name' => 'Sam'])
            ->push(['name' => 'Sam'])
            ->push(['status' => 'Too Many Requests'], 429)
            ->push(['name' => 'Sam'])]);

        $connector->send(new UserRequest);

        $this->assertTrue(Saloon::inspectRateLimit($connector, $cooldown)->allowed());

        $connector->send(new UserRequest);
        $connector->send(new UserRequest);

        $this->assertTrue(Saloon::inspectRateLimit($connector, $cooldown)->allowed());
        $this->assertSame(200, $connector->send(new UserRequest)->status());
    }

    public function testYouCanCustomiseThe429ErrorDetectionToSleepInstead(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $connector = new SleepTooManyRequestsConnector([]);

        Http::fake(['*' => Http::sequence()
            ->push(['name' => 'Sam'])
            ->push(['status' => 'Too Many Requests'], 429)
            ->push(['name' => 'Jon'])]);

        $connector->send(new UserRequest);

        $response = $connector->send(new UserRequest);

        $this->assertSame(['name' => 'Jon'], $response->json());
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
        Http::assertSentCount(3);
    }

    public function testTheRateLimiterCanBeUsedOnARequest(): void
    {
        $connector = new BaseConnector;
        $request = new LimitedRequest;
        $limit = Limit::perMinute(60);

        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        // Let's start by making sure nothing has been recorded

        $this->assertSame(60, Saloon::inspectRateLimit($request, $limit)->remaining());

        $connector->send($request);

        $result = Saloon::inspectRateLimit($request, $limit);
        $this->assertSame(59, $result->remaining());
        $this->assertSame(60, $result->resetAfter());
        $this->assertTrue(Saloon::inspectRateLimit($request, Cooldown::for(null))->allowed());
    }

    public function testWhenTheTheRateLimiterIsUsedOnBothTheConnectorOrRequestAllTheLimitsAreUsed(): void
    {
        $connectorLimit = Limit::perMinute(3);
        $requestLimit = Limit::perMinute(60);
        $connector = new TestConnector([$connectorLimit]);
        $request = new LimitedRequest;

        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        $connector->send($request);

        $result = Saloon::inspectRateLimit($connector, $connectorLimit);
        $this->assertSame(2, $result->remaining());
        $this->assertSame(60, $result->resetAfter());

        $result = Saloon::inspectRateLimit($request, $requestLimit);
        $this->assertSame(59, $result->remaining());
        $this->assertSame(60, $result->resetAfter());

        $this->assertTrue(Saloon::inspectRateLimit($connector, Cooldown::for(null))->allowed());
        $this->assertTrue(Saloon::inspectRateLimit($request, Cooldown::for(null))->allowed());
    }

    public function testTheRateLimiterCanBeUsedOnASoloRequest(): void
    {
        $request = new LimitedSoloRequest;
        $limit = Limit::perMinute(60);

        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        $this->assertSame(60, Saloon::inspectRateLimit($request, $limit)->remaining());

        $request->send();

        $result = Saloon::inspectRateLimit($request, $limit);
        $this->assertSame(59, $result->remaining());
        $this->assertSame(60, $result->resetAfter());
        $this->assertTrue(Saloon::inspectRateLimit($request, Cooldown::for(null))->allowed());
    }

    // REMOVED: you can specify a custom closure to determine the limiter based on response - Limit::custom() is not
    // included. Response-driven limits override resolveRateLimitCooldown(), as
    // testYouCanCustomiseWhenTheTooManyRequestsLimitIsApplied covers.

    public function testIfAConnectorHasTheAlwaysThrowOnErrorTraitThenTheLimiterWillTakePriority(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new ThrowConnector([$limit]);

        Http::fake(['*' => Http::sequence()
            ->push(['name' => 'Sam'], 500)
            ->push(['status' => 'Too Many Requests'], 429, ['Retry-After' => '10'])]);

        $thrown = false;

        try {
            $connector->send(new UserRequest);
        } catch (InternalServerErrorException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);

        // The limit was charged before the request was sent, and a 429's cooldown is recorded before the response
        // throws.

        $this->assertSame(2, Saloon::inspectRateLimit($connector, $limit)->remaining());

        $thrown = false;

        try {
            $connector->send(new UserRequest);
        } catch (TooManyRequestsException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame(10, Saloon::inspectRateLimit($connector, Cooldown::for(null))->retryAfter());
    }

    public function testTheLimitIsGivenTheCorrectPrefixEvenWithCustomNames(): void
    {
        $limit = Limit::perMinute(3);
        $namedLimit = Limit::perMinute(3)->by('custom_name');
        $connector = new TestConnector([$limit, $namedLimit]);

        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        $connector->send(new UserRequest);

        // Both limits are recorded under the connector's limiter name, so another name does not see them

        $this->assertSame(2, Saloon::inspectRateLimit($connector, $limit)->remaining());
        $this->assertSame(2, Saloon::inspectRateLimit($connector, $namedLimit)->remaining());
        $this->assertSame(3, Saloon::inspectRateLimit(new CustomPrefixConnector([]), $limit)->remaining());
        $this->assertSame(3, Saloon::inspectRateLimit(new CustomPrefixConnector([]), $namedLimit)->remaining());
    }

    public function testYouCanCustomiseThePrefix(): void
    {
        $limit = Limit::perMinute(3);
        $namedLimit = Limit::perMinute(3)->by('custom_name');
        $connector = new CustomPrefixConnector([$limit, $namedLimit]);
        $sharingConnector = new class([$limit]) extends TestConnector {
            /**
             * Resolve the limiter name.
             */
            protected function resolveRateLimiterName(): string
            {
                return 'custom';
            }
        };

        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        $connector->send(new UserRequest);
        $sharingConnector->send(new UserRequest);

        // Connectors with the same limiter name share the state of the same policy

        $this->assertSame(1, Saloon::inspectRateLimit($connector, $limit)->remaining());
        $this->assertSame(2, Saloon::inspectRateLimit($connector, $namedLimit)->remaining());
        $this->assertSame(3, Saloon::inspectRateLimit(new TestConnector([]), $limit)->remaining());
    }

    public function testResourcesWithTheSameLimiterNameShareCooldownsUnlessTheirCooldownKeysDiffer(): void
    {
        $connector = new CustomPrefixConnector([]);
        $sharingConnector = new class([]) extends TestConnector {
            /**
             * Resolve the limiter name.
             */
            protected function resolveRateLimiterName(): string
            {
                return 'custom';
            }
        };
        $otherAccountConnector = new class([]) extends CustomPrefixConnector {
            /**
             * Resolve the cooldown key.
             */
            protected function resolveRateLimitCooldownKey(PendingRequest $pendingRequest): string
            {
                return 'account:other';
            }
        };

        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'Too Many Requests'], 429, ['Retry-After' => '30'])
            ->push(['name' => 'Sam'])]);

        $this->assertSame(429, $connector->send(new UserRequest)->status());

        try {
            $sharingConnector->send(new UserRequest);
            $this->fail('A shared cooldown reached transport.');
        } catch (RateLimitReachedException $exception) {
            $this->assertSame(30, $exception->result()->retryAfter());
        }

        $this->assertSame(200, $otherAccountConnector->send(new UserRequest)->status());
        Http::assertSentCount(2);
    }

    // Connectors are read-only, so an operation-aware rateLimitingEnabled() replaces upstream's useRateLimitPlugin().
    public function testYouCanProgrammaticallyDisableTheRateLimiting(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new BaseConnector;

        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'Too Many Requests'], 429, ['Retry-After' => '30'])
            ->push(['status' => 'Too Many Requests'], 429, ['Retry-After' => '90'])
            ->push(['name' => 'Sam'])]);

        $this->assertSame(429, $connector->send(new OptionallyLimitedRequest($limit))->status());
        $this->assertSame(2, Saloon::inspectRateLimit(new OptionallyLimitedRequest($limit), $limit)->remaining());

        // A disabled operation ignores the active cooldown and its policies, and records no cooldown of its own

        $this->assertSame(429, $connector->send(new OptionallyLimitedRequest($limit, limited: false))->status());
        $this->assertSame(200, $connector->send(new OptionallyLimitedRequest($limit, limited: false))->status());
        $this->assertSame(2, Saloon::inspectRateLimit(new OptionallyLimitedRequest($limit), $limit)->remaining());

        try {
            $connector->send(new OptionallyLimitedRequest($limit));
            $this->fail('The enabled request ignored the recorded cooldown.');
        } catch (RateLimitReachedException $exception) {
            $this->assertSame(30, $exception->result()->retryAfter());
        }

        Http::assertSentCount(3);
    }

    // REMOVED: it will fail if you dont configure a limiter properly - a limit without a window is rejected when it is
    // created. See RateLimiter/LimitTest::testInvalidScalarValuesAreRejected.

    public function testAResourceCanWaitForOneLimitAndThrowForAnother(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $burstLimit = Limit::perSecond(1);
        $dailyLimit = Limit::perDay(2)->by('daily');
        $connector = new TestConnector([$burstLimit, $dailyLimit], waitFor: [$burstLimit]);

        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        $connector->send(new UserRequest);
        $connector->send(new UserRequest);

        try {
            $connector->send(new UserRequest);
            $this->fail('The daily limit was awaited.');
        } catch (RateLimitReachedException $exception) {
            $this->assertSame(
                sprintf('The request rate limit [saloon:%s:daily] has been reached.', TestConnector::class),
                $exception->getMessage(),
            );
            $this->assertSame($dailyLimit, $exception->policy());
        }

        Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(1)->seconds()]);
        Http::assertSentCount(2);
    }
}

class ThrowConnector extends TestConnector
{
    use AlwaysThrowOnErrors;
}

class OptionallyLimitedRequest extends Request
{
    use HasRateLimits;

    /**
     * Define the HTTP method.
     */
    protected Method $method = Method::GET;

    /**
     * Create a request whose limit applies unless it is disabled.
     */
    public function __construct(
        protected Limit $limit,
        protected bool $limited = true,
    ) {
    }

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /**
     * Determine if the request's rate limits apply to an operation.
     */
    protected function rateLimitingEnabled(PendingRequest $pendingRequest): bool
    {
        return $this->limited;
    }

    /**
     * Resolve the limits.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [$this->limit];
    }
}
