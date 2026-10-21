<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests\UserRequest;
use PHPUnit\Framework\Attributes\DataProvider;

// Upstream's RetryAfterHelper is the parsing inside HasRateLimits::resolveRateLimitCooldown(), so these cases read
// it through the public resolveRateLimitCooldownFor(). Hypervel accepts only delay-seconds and the RFC 9110
// HTTP-date formats, so a value strtotime() would read, such as an ISO date, gets the 60-second default.
class HelperTest extends TestCase
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
    }

    #[DataProvider('retryAfterProvider')]
    public function testTheRetryAfterHelperCanParseDifferentValues(string $now, ?string $retryAfter, ?int $expected): void
    {
        $this->assertSame($expected, $this->cooldownAt($now, $retryAfter));
    }

    /**
     * Get Retry-After values, the time they are received and the cooldown they give.
     */
    public static function retryAfterProvider(): array
    {
        $now = '2026-08-06 12:00:00';
        $secondsUntil = static fn (string $time): int => CarbonImmutable::parse($time . ' UTC')->getTimestamp()
            - CarbonImmutable::parse($now . ' UTC')->getTimestamp();

        return [
            'missing' => [$now, null, 60],
            'zero seconds' => [$now, '0', null],
            'seconds' => [$now, '120', 120],
            'seconds with leading zeros' => [$now, '0008', 8],
            'too long for the rate limiter' => [$now, str_repeat('9', 30), 60],
            'unknown' => [$now, 'Unknown', 60],
            'not an HTTP date' => [$now, '2030-01-01 00:00:00', 60],
            'HTTP date' => [$now, 'Wed, 23 Oct 2030 06:28:00 GMT', $secondsUntil('2030-10-23 06:28:00')],
            'HTTP date with the wrong weekday' => [$now, 'Fri, 06 Aug 2026 12:00:07 GMT', 7],
            'HTTP date in the past' => [$now, 'Wed, 05 Aug 2026 12:00:00 GMT', null],
            'leap second' => [$now, 'Thu, 06 Aug 2026 12:01:60 GMT', 120],
            'invalid calendar date' => [$now, 'Thu, 31 Feb 2026 12:00:09 GMT', 60],
            'invalid time' => [$now, 'Thu, 06 Aug 2026 25:00:09 GMT', 60],
            'RFC 850 date' => [$now, 'Thursday, 06-Aug-26 12:00:05 GMT', 5],
            'asctime date' => [$now, 'Thu Aug  6 12:00:06 2026', 6],
            'asctime date with a two-digit day' => ['2026-08-13 12:00:00', 'Thu Aug 13 12:00:07 2026', 7],

            // A two-digit RFC 850 year more than 50 years ahead means the most recent past year with those digits.
            'RFC 850 year within 50 years' => [$now, 'Sunday, 06-Nov-70 12:00:00 GMT', $secondsUntil('2070-11-06 12:00:00')],
            'RFC 850 year 50 years ahead' => [$now, 'Thursday, 06-Aug-76 12:00:00 GMT', $secondsUntil('2076-08-06 12:00:00')],
            'RFC 850 year earlier in that year' => [$now, 'Thursday, 01-Jan-76 12:00:00 GMT', $secondsUntil('2076-01-01 12:00:00')],
            'RFC 850 year beyond 50 years' => [$now, 'Thursday, 31-Dec-76 12:00:00 GMT', null],
            'four-digit year beyond 50 years' => [$now, 'Thu, 31 Dec 2076 12:00:00 GMT', $secondsUntil('2076-12-31 12:00:00')],
        ];
    }

    public function testRfc850YearsResolveAgainstGmtAtACenturyBoundary(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');

        try {
            $this->assertSame(
                CarbonImmutable::parse('2150-01-01 00:30:00 UTC')->getTimestamp()
                    - CarbonImmutable::parse('2100-01-01 00:30:00 UTC')->getTimestamp(),
                $this->cooldownAt('2100-01-01 00:30:00', 'Sunday, 01-Jan-50 00:30:00 GMT'),
            );
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    // REMOVED: the limit helper clones the limits - rate-limit policies are immutable, so the configured policies
    // are never changed (RateLimiter/LimitTest::testFluentModifiersReturnImmutableCopies).

    // REMOVED: the limit helper throws an exception if two of the same limits have the same name - identical
    // policies share one state and charge it once each, as RateLimiter/Fixtures/RateLimiterStoreContract's
    // repeated-key case shows.

    /**
     * Get the cooldown a 429 with the given Retry-After header gives at a UTC time.
     */
    private function cooldownAt(string $now, ?string $retryAfter): ?int
    {
        CarbonImmutable::setTestNow($now . ' UTC');
        Http::fake(['*' => Http::response([], 429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter])]);
        $connector = new TestConnector([]);

        return $connector->resolveRateLimitCooldownFor($connector->send(new UserRequest));
    }
}
