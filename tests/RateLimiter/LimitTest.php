<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Unlimited;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LimitTest extends TestCase
{
    public function testFactoriesCreateFixedWindowPolicies(): void
    {
        $this->assertPolicy(Limit::perSecond(2, 3), 2, 3);
        $this->assertPolicy(Limit::perMinute(4, 5), 4, 300);
        $this->assertPolicy(Limit::perMinutes(6, 7), 7, 360);
        $this->assertPolicy(Limit::perHour(8, 9), 8, 32400);
        $this->assertPolicy(Limit::perDay(10, 11), 10, 950400);
        $this->assertInstanceOf(Unlimited::class, Limit::none());
    }

    #[DataProvider('decayOverflowProvider')]
    public function testFactoryOverflowNamesItsPublicDecayUnit(callable $factory, string $unit): void
    {
        $this->expectException(InvalidRateLimitException::class);
        $this->expectExceptionMessage("The rate limit decay {$unit} exceeds the maximum supported duration.");

        $factory();
    }

    public static function decayOverflowProvider(): array
    {
        return [
            'seconds' => [static fn () => Limit::perSecond(1, 9_007_199_255), 'seconds'],
            'minutes' => [static fn () => Limit::perMinute(1, 150_119_988), 'minutes'],
            'hours' => [static fn () => Limit::perHour(1, 2_502_000), 'hours'],
            'days' => [static fn () => Limit::perDay(1, 104_250), 'days'],
        ];
    }

    // REMOVED: Laravel's GlobalLimit constructor coverage is replaced by the
    // immutable AdmissionPolicy::globally() modifier coverage below.

    public function testFluentModifiersReturnImmutableCopies(): void
    {
        $after = static fn (): bool => true;
        $response = static fn (): string => 'limited';
        $original = Limit::perMinute(10);
        $modified = $original
            ->by(123)
            ->cost(3)
            ->globally()
            ->after($after)
            ->response($response);

        $this->assertNotSame($original, $modified);
        $this->assertSame('', $original->key);
        $this->assertSame(1, $original->cost);
        $this->assertFalse($original->global);
        $this->assertNull($original->afterCallback);
        $this->assertNull($original->responseCallback);

        $this->assertSame('123', $modified->key);
        $this->assertSame(3, $modified->cost);
        $this->assertTrue($modified->global);
        $this->assertSame($after, $modified->afterCallback);
        $this->assertSame($response, $modified->responseCallback);
        $this->assertSame(10, $modified->maxAttempts);
        $this->assertSame(60, $modified->decaySeconds);
    }

    public function testInvalidScalarValuesAreRejected(): void
    {
        foreach ([
            static fn () => Limit::perMinute(0),
            static fn () => Limit::perSecond(1, 0),
            static fn () => Limit::perMinute(1)->cost(0),
            static fn () => Limit::perSecond(1, intdiv(Limit::MAX_INTEGER, 1_000_000) + 1),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected an invalid rate limit exception.');
            } catch (InvalidRateLimitException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function assertPolicy(Limit $limit, int $attempts, int $seconds): void
    {
        $this->assertSame($attempts, $limit->maxAttempts);
        $this->assertSame($seconds, $limit->decaySeconds);
    }
}
