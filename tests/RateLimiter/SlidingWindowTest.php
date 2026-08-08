<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SlidingWindowTest extends TestCase
{
    public function testFactoriesCreateSlidingWindowPolicies(): void
    {
        $this->assertPolicy(SlidingWindow::perSecond(maxAttempts: 2, windowSeconds: 3), 2, 3);
        $this->assertPolicy(SlidingWindow::perMinute(maxAttempts: 4, windowMinutes: 5), 4, 300);
        $this->assertPolicy(SlidingWindow::perMinutes(windowMinutes: 6, maxAttempts: 7), 7, 360);
        $this->assertPolicy(SlidingWindow::perHour(maxAttempts: 8, windowHours: 2), 8, 7200);
        $this->assertPolicy(SlidingWindow::perDay(maxAttempts: 10, windowDays: 2), 10, 172800);
    }

    public function testFactoriesAcceptTheirMaximumSupportedWindow(): void
    {
        $this->assertSame(4_503_599_627, SlidingWindow::perSecond(1, 4_503_599_627)->windowSeconds);
        $this->assertSame(4_503_599_580, SlidingWindow::perMinute(1, 75_059_993)->windowSeconds);
        $this->assertSame(4_503_596_400, SlidingWindow::perHour(1, 1_250_999)->windowSeconds);
        $this->assertSame(4_503_513_600, SlidingWindow::perDay(1, 52_124)->windowSeconds);
    }

    #[DataProvider('windowOverflowProvider')]
    public function testFactoryOverflowNamesItsPublicWindowUnit(callable $factory, string $unit): void
    {
        $this->expectException(InvalidRateLimitException::class);
        $this->expectExceptionMessage("The rate limit window {$unit} exceeds the maximum supported duration.");

        $factory();
    }

    public static function windowOverflowProvider(): array
    {
        return [
            'seconds' => [static fn () => SlidingWindow::perSecond(1, 4_503_599_628), 'seconds'],
            'minutes' => [static fn () => SlidingWindow::perMinute(1, 75_059_994), 'minutes'],
            'hours' => [static fn () => SlidingWindow::perHour(1, 1_251_000), 'hours'],
            'days' => [static fn () => SlidingWindow::perDay(1, 52_125), 'days'],
        ];
    }

    public function testFluentModifiersReturnImmutableCopies(): void
    {
        $after = static fn (): bool => true;
        $response = static fn (): string => 'limited';
        $original = SlidingWindow::perMinute(100);
        $modified = $original
            ->by('uploads')
            ->cost(5)
            ->globally()
            ->after($after)
            ->response($response);

        $this->assertNotSame($original, $modified);
        $this->assertSame('', $original->key);
        $this->assertSame(1, $original->cost);
        $this->assertFalse($original->global);
        $this->assertNull($original->afterCallback);
        $this->assertNull($original->responseCallback);

        $this->assertSame('uploads', $modified->key);
        $this->assertSame(5, $modified->cost);
        $this->assertTrue($modified->global);
        $this->assertSame($after, $modified->afterCallback);
        $this->assertSame($response, $modified->responseCallback);
        $this->assertSame(100, $modified->maxAttempts);
        $this->assertSame(60, $modified->windowSeconds);
    }

    public function testMaximumSupportedCapacityIsAccepted(): void
    {
        $this->assertSame(9_007_199_254, SlidingWindow::perMinute(9_007_199_254)->maxAttempts);
    }

    public function testCapacityAboveTheExactIntegerCeilingIsRejected(): void
    {
        $this->expectException(InvalidRateLimitException::class);
        $this->expectExceptionMessage('The sliding-window capacity may not exceed 9007199254.');

        SlidingWindow::perMinute(9_007_199_255);
    }

    public function testInvalidScalarValuesAreRejected(): void
    {
        foreach ([
            static fn () => SlidingWindow::perMinute(0),
            static fn () => SlidingWindow::perSecond(1, 0),
            static fn () => SlidingWindow::perMinute(1)->cost(0),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected an invalid rate limit exception.');
            } catch (InvalidRateLimitException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function assertPolicy(SlidingWindow $limit, int $attempts, int $seconds): void
    {
        $this->assertSame($attempts, $limit->maxAttempts);
        $this->assertSame($seconds, $limit->windowSeconds);
    }
}
