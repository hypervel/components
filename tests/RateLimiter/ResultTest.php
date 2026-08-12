<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\BackoffResult;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ResultTest extends TestCase
{
    public function testLimitResultExposesRoundedPublicDurations(): void
    {
        $result = new LimitResult(false, 10, 3, 1_000_001, 2_500_000);

        $this->assertFalse($result->allowed());
        $this->assertTrue($result->denied());
        $this->assertSame(10, $result->limit());
        $this->assertSame(3, $result->remaining());
        $this->assertSame(2, $result->retryAfter());
        $this->assertSame(3, $result->resetAfter());
    }

    public function testBackoffResultExposesRoundedPublicDelay(): void
    {
        $result = new BackoffResult(false, 7, 1_000_001);

        $this->assertFalse($result->allowed());
        $this->assertTrue($result->denied());
        $this->assertSame(7, $result->failures());
        $this->assertSame(2, $result->retryAfter());
    }

    public function testInvalidResultInvariantsAreRejected(): void
    {
        foreach ([
            static fn () => new LimitResult(true, 0, 0, 0, 0),
            static fn () => new LimitResult(true, 1, 2, 0, 0),
            static fn () => new LimitResult(true, 1, 0, 1, 0),
            static fn () => new LimitResult(false, 1, 0, 0, 0),
            static fn () => new BackoffResult(true, -1, 0),
            static fn () => new BackoffResult(true, 0, 1),
            static fn () => new BackoffResult(false, 0, 0),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected an invalid result exception.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
