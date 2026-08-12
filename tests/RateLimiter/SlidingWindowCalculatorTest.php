<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Concerns\CalculatesRateLimits;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use UnexpectedValueException;

class SlidingWindowCalculatorTest extends TestCase
{
    public function testMissingInspectionAndFirstConsumption(): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond(10, 2)->cost(2);
        $state = [0, 0, 0];

        [$inspection, $inspectedState] = $calculator->inspect($policy, 10_000_999, $state);

        $this->assertTrue($inspection->allowed());
        $this->assertSame(10, $inspection->remaining());
        $this->assertSame(0, $this->retryMicroseconds($inspection));
        $this->assertSame(0, $this->resetMicroseconds($inspection));
        $this->assertSame($state, $inspectedState);

        [$result, $consumedState] = $calculator->consume($policy, 10_000_999, $state);

        $this->assertTrue($result->allowed());
        $this->assertSame(8, $result->remaining());
        $this->assertSame(4_000_000, $this->resetMicroseconds($result));
        $this->assertSame([2, 0, 14_000_000], $consumedState);
    }

    public function testSameWindowConsumptionRetainsExpiry(): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond(10);
        $state = [2, 4, 11_500_000];

        [$inspection, $inspectedState] = $calculator->inspect($policy, 10_000_000, $state);
        [$result, $consumedState] = $calculator->consume($policy, 10_000_000, $state);

        $this->assertTrue($inspection->allowed());
        $this->assertSame(6, $inspection->remaining());
        $this->assertSame($state, $inspectedState);
        $this->assertTrue($result->allowed());
        $this->assertSame(5, $result->remaining());
        $this->assertSame([3, 4, 11_500_000], $consumedState);
        $this->assertSame(1_500_000, $this->resetMicroseconds($result));
    }

    public function testAcceptedLogicalRotationExtendsExpiry(): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond(10)->cost(2);
        $state = [4, 2, 12_000_000];

        [$inspection, $inspectedState] = $calculator->inspect($policy, 11_000_000, $state);
        [$result, $consumedState] = $calculator->consume($policy, 11_000_000, $state);

        $this->assertTrue($inspection->allowed());
        $this->assertSame(6, $inspection->remaining());
        $this->assertSame($state, $inspectedState);
        $this->assertTrue($result->allowed());
        $this->assertSame(4, $result->remaining());
        $this->assertSame([2, 4, 13_000_000], $consumedState);
        $this->assertSame(2_000_000, $this->resetMicroseconds($result));
    }

    public function testRotatedDenialKeepsStoredStateUnchanged(): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond(10)->cost(7);
        $state = [8, 2, 10_500_000];

        [$result, $returnedState] = $calculator->consume($policy, 10_000_000, $state);

        $this->assertTrue($result->denied());
        $this->assertSame(6, $result->remaining());
        $this->assertSame(1000, $this->retryMicroseconds($result));
        $this->assertSame(500_000, $this->resetMicroseconds($result));
        $this->assertSame($state, $returnedState);
    }

    #[DataProvider('boundaryProvider')]
    public function testBoundaryPositionsUseMillisecondQuantization(int $now, int $remaining): void
    {
        $calculator = new SlidingWindowCalculator;
        $state = [4, 2, 12_000_000];

        [$result, $returnedState] = $calculator->inspect(
            SlidingWindow::perSecond(10),
            $now,
            $state,
        );

        $this->assertTrue($result->allowed());
        $this->assertSame($remaining, $result->remaining());
        $this->assertSame($state, $returnedState);
    }

    public static function boundaryProvider(): array
    {
        return [
            'immediately before' => [10_999_000, 6],
            'exactly at' => [11_000_000, 6],
            'immediately after' => [11_001_000, 7],
        ];
    }

    public function testExpiredStateIsLogicallyEmptyWithoutMutatingInspection(): void
    {
        $calculator = new SlidingWindowCalculator;
        $state = [4, 2, 12_000_000];

        [$result, $returnedState] = $calculator->inspect(
            SlidingWindow::perSecond(10),
            14_000_000,
            $state,
        );

        $this->assertTrue($result->allowed());
        $this->assertSame(10, $result->remaining());
        $this->assertSame(0, $this->resetMicroseconds($result));
        $this->assertSame($state, $returnedState);
    }

    public function testWeightedDenialReportsTheFirstAdmissibleMillisecond(): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond(8)->cost(2);
        $state = [2, 8, 11_625_000];

        [$result, $returnedState] = $calculator->consume($policy, 10_000_000, $state);

        $this->assertTrue($result->denied());
        $this->assertSame(1, $result->remaining());
        $this->assertSame(1000, $this->retryMicroseconds($result));
        $this->assertSame(1_625_000, $this->resetMicroseconds($result));
        $this->assertSame($state, $returnedState);
    }

    public function testCapacityDenialWaitsForTheBoundaryAndPreviousWeight(): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond(10)->cost(3);
        $state = [8, 0, 11_500_000];

        [$result, $returnedState] = $calculator->consume($policy, 10_000_000, $state);

        $this->assertTrue($result->denied());
        $this->assertSame(2, $result->remaining());
        $this->assertSame(501_000, $this->retryMicroseconds($result));
        $this->assertSame(1_500_000, $this->resetMicroseconds($result));
        $this->assertSame($state, $returnedState);
    }

    public function testBackwardClockMovementKeepsRawDurations(): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond(6);
        $state = [2, 4, 13_000_000];

        [$result, $returnedState] = $calculator->consume($policy, 10_000_000, $state);

        $this->assertTrue($result->denied());
        $this->assertSame(0, $result->remaining());
        $this->assertSame(1_001_000, $this->retryMicroseconds($result));
        $this->assertSame(3_000_000, $this->resetMicroseconds($result));
        $this->assertSame($state, $returnedState);
    }

    public function testBackwardClockMovementClampsWeightForAdmissionDecision(): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond(6);
        $state = [1, 4, 13_000_000];

        [$result, $returnedState] = $calculator->consume($policy, 10_000_000, $state);

        $this->assertTrue($result->allowed());
        $this->assertSame(0, $result->remaining());
        $this->assertSame(0, $this->retryMicroseconds($result));
        $this->assertSame(3_000_000, $this->resetMicroseconds($result));
        $this->assertSame([2, 4, 13_000_000], $returnedState);
    }

    #[DataProvider('corruptStateProvider')]
    public function testCorruptStateFailsClosed(array $state): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new SlidingWindowCalculator)->inspect(
            SlidingWindow::perSecond(10),
            10_000_000,
            $state,
        );
    }

    public static function corruptStateProvider(): array
    {
        return [
            'zero current with expiry' => [[0, 0, 12_000_000]],
            'zero current with previous' => [[0, 1, 12_000_000]],
            'missing expiry' => [[1, 0, 0]],
            'negative current' => [[-1, 0, 12_000_000]],
            'negative previous' => [[1, -1, 12_000_000]],
            'current above capacity' => [[11, 0, 12_000_000]],
            'previous above capacity' => [[1, 11, 12_000_000]],
            'expiry above exact range' => [[1, 0, AdmissionPolicy::MAX_INTEGER + 1]],
        ];
    }

    #[DataProvider('highFrequencyWeightProvider')]
    public function testHighFrequencyWeightsDoNotRoundUp(int $previous, int $weightedPrevious): void
    {
        $calculator = new SlidingWindowCalculator;
        $policy = SlidingWindow::perSecond($previous + 2);

        [$result] = $calculator->inspect(
            $policy,
            10_000_000,
            [1, $previous, 11_999_000],
        );

        $this->assertSame($policy->maxAttempts - 1 - $weightedPrevious, $result->remaining());
    }

    public static function highFrequencyWeightProvider(): array
    {
        return [
            '999' => [999, 998],
            '1000' => [1000, 999],
            '1001' => [1001, 999],
            '2000' => [2000, 1998],
        ];
    }

    public function testExactIntegerCeilingsRemainSafe(): void
    {
        $calculator = new SlidingWindowCalculator;
        $capacity = 9_007_199_254;
        $policy = SlidingWindow::perSecond($capacity, 4_503_599_627);

        [$first, $firstState] = $calculator->consume($policy, 0, [0, 0, 0]);
        [$weighted] = $calculator->inspect(
            $policy,
            0,
            [1, $capacity, 9_007_199_254_000_000],
        );

        $this->assertTrue($first->allowed());
        $this->assertSame([1, 0, 9_007_199_254_000_000], $firstState);
        $this->assertSame(740_991, AdmissionPolicy::MAX_INTEGER - $firstState[2]);
        $this->assertTrue($weighted->denied());
        $this->assertSame(0, $weighted->remaining());
        $this->assertGreaterThan(0, $this->retryMicroseconds($weighted));
    }

    public function testRetryCalculationIsExactAcrossTheExhaustiveSmallDomain(): void
    {
        $calculator = new SlidingWindowCalculator;
        $now = 10_000_000;
        $checked = 0;

        foreach ([1, 2] as $windowSeconds) {
            $windowMilliseconds = $windowSeconds * 1000;
            $windowMicroseconds = $windowMilliseconds * 1000;

            for ($previous = 1; $previous <= 8; ++$previous) {
                for ($available = 0; $available < $previous; ++$available) {
                    $policy = SlidingWindow::perSecond($previous + 1, $windowSeconds);
                    $current = $previous - $available;

                    for ($remainingMilliseconds = 1; $remainingMilliseconds <= $windowMilliseconds; ++$remainingMilliseconds) {
                        $weightedPrevious = intdiv(
                            $previous * $remainingMilliseconds,
                            $windowMilliseconds,
                        );

                        if ($weightedPrevious <= $available) {
                            continue;
                        }

                        $state = [
                            $current,
                            $previous,
                            $now + $windowMicroseconds + ($remainingMilliseconds * 1000),
                        ];
                        [$result, $returnedState] = $calculator->consume($policy, $now, $state);
                        $retryMilliseconds = intdiv($this->retryMicroseconds($result), 1000);
                        $weightAtRetry = intdiv(
                            $previous * ($remainingMilliseconds - $retryMilliseconds),
                            $windowMilliseconds,
                        );
                        $weightBeforeRetry = intdiv(
                            $previous * ($remainingMilliseconds - $retryMilliseconds + 1),
                            $windowMilliseconds,
                        );

                        if (! $result->denied()
                            || $returnedState !== $state
                            || $retryMilliseconds < 1
                            || $weightAtRetry > $available
                            || $weightBeforeRetry <= $available) {
                            $this->fail(sprintf(
                                'Retry mismatch for window=%d, previous=%d, available=%d, remaining=%d.',
                                $windowSeconds,
                                $previous,
                                $available,
                                $remainingMilliseconds,
                            ));
                        }

                        ++$checked;
                    }
                }
            }
        }

        $this->assertSame(42_060, $checked);
    }

    private function retryMicroseconds(LimitResult $result): int
    {
        return (new ReflectionProperty($result, 'retryAfterMicroseconds'))->getValue($result);
    }

    private function resetMicroseconds(LimitResult $result): int
    {
        return (new ReflectionProperty($result, 'resetAfterMicroseconds'))->getValue($result);
    }
}

class SlidingWindowCalculator
{
    use CalculatesRateLimits;

    /**
     * @param array{int, int, int} $state
     * @return array{LimitResult, array{int, int, int}}
     */
    public function consume(SlidingWindow $policy, int $now, array $state): array
    {
        [$value, $secondaryValue, $expiresAt] = $state;
        $result = $this->calculateConsume($policy, $now, $value, $secondaryValue, $expiresAt);

        return [$result, [$value, $secondaryValue, $expiresAt]];
    }

    /**
     * @param array{int, int, int} $state
     * @return array{LimitResult, array{int, int, int}}
     */
    public function inspect(SlidingWindow $policy, int $now, array $state): array
    {
        [$value, $secondaryValue, $expiresAt] = $state;
        $result = $this->calculateInspection($policy, $now, $value, $secondaryValue, $expiresAt);

        return [$result, [$value, $secondaryValue, $expiresAt]];
    }
}
