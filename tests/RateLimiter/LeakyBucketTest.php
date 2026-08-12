<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LeakyBucketTest extends TestCase
{
    public function testFactoriesCreateLeakyBucketPolicies(): void
    {
        $this->assertPolicy(LeakyBucket::perSecond(rate: 2, periodSeconds: 3), 2, 3_000_000, 2);
        $this->assertPolicy(LeakyBucket::perMinute(rate: 4, periodMinutes: 5), 4, 300_000_000, 4);
        $this->assertPolicy(LeakyBucket::perMinutes(periodMinutes: 6, rate: 7), 7, 360_000_000, 7);
        $this->assertPolicy(LeakyBucket::perHour(rate: 8, periodHours: 2), 8, 7_200_000_000, 8);
        $this->assertPolicy(LeakyBucket::perDay(rate: 10, periodDays: 2), 10, 172_800_000_000, 10);
    }

    #[DataProvider('periodOverflowProvider')]
    public function testFactoryOverflowNamesItsPublicPeriodUnit(callable $factory, string $unit): void
    {
        $this->expectException(InvalidRateLimitException::class);
        $this->expectExceptionMessage("The rate limit period {$unit} exceeds the maximum supported duration.");

        $factory();
    }

    public static function periodOverflowProvider(): array
    {
        return [
            'seconds' => [static fn () => LeakyBucket::perSecond(rate: 1, periodSeconds: 9_007_199_255), 'seconds'],
            'minutes' => [static fn () => LeakyBucket::perMinute(rate: 1, periodMinutes: 150_119_988), 'minutes'],
            'hours' => [static fn () => LeakyBucket::perHour(rate: 1, periodHours: 2_502_000), 'hours'],
            'days' => [static fn () => LeakyBucket::perDay(rate: 1, periodDays: 104_250), 'days'],
        ];
    }

    public function testBurstAndCostMayBeConfiguredInEitherOrder(): void
    {
        $costThenBurst = LeakyBucket::perSecond(100)->cost(150)->burst(200);
        $burstThenCost = LeakyBucket::perSecond(100)->burst(200)->cost(150);

        $this->assertSame(150, $costThenBurst->cost);
        $this->assertSame(200, $costThenBurst->burst);
        $this->assertSame(150, $burstThenCost->cost);
        $this->assertSame(200, $burstThenCost->burst);
    }

    public function testBurstPreservesSharedPolicyValues(): void
    {
        $after = static fn (): bool => true;
        $response = static fn (): string => 'limited';
        $original = LeakyBucket::perSecond(100)
            ->by('api')
            ->cost(2)
            ->globally()
            ->after($after)
            ->response($response);

        $modified = $original->burst(200);

        $this->assertNotSame($original, $modified);
        $this->assertSame(100, $original->burst);
        $this->assertSame(200, $modified->burst);
        $this->assertSame('api', $modified->key);
        $this->assertSame(2, $modified->cost);
        $this->assertTrue($modified->global);
        $this->assertSame($after, $modified->afterCallback);
        $this->assertSame($response, $modified->responseCallback);
        $this->assertSame(100, $modified->rate);
        $this->assertSame(1_000_000, $modified->periodMicroseconds);
    }

    public function testStrictSmoothingUsesABurstOfOne(): void
    {
        $policy = LeakyBucket::perSecond(100)->burst(1);

        $this->assertSame(100, $policy->rate);
        $this->assertSame(1, $policy->burst);
    }

    public function testInvalidScalarValuesAreRejected(): void
    {
        foreach ([
            static fn () => LeakyBucket::perSecond(0),
            static fn () => LeakyBucket::perSecond(1, 0),
            static fn () => LeakyBucket::perSecond(1)->burst(0),
            static fn () => new LeakyBucket(2, 1, 2),
            static fn () => new LeakyBucket(1, 2, LeakyBucket::MAX_INTEGER),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected an invalid rate limit exception.');
            } catch (InvalidRateLimitException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function assertPolicy(
        LeakyBucket $limit,
        int $rate,
        int $periodMicroseconds,
        int $burst,
    ): void {
        $this->assertSame($rate, $limit->rate);
        $this->assertSame($periodMicroseconds, $limit->periodMicroseconds);
        $this->assertSame($burst, $limit->burst);
    }
}
