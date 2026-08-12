<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\Tests\TestCase;

class BackoffTest extends TestCase
{
    public function testExponentialFactoryCreatesAnImmutablePolicy(): void
    {
        $original = Backoff::exponential(
            after: 5,
            initialDelay: 2,
            maxDelay: 120,
            resetAfter: 600,
        );
        $modified = $original->by(123);

        $this->assertSame(5, $original->after);
        $this->assertSame(2, $original->initialDelay);
        $this->assertSame(120, $original->maxDelay);
        $this->assertSame(600, $original->resetAfter);
        $this->assertSame('', $original->key);
        $this->assertNotSame($original, $modified);
        $this->assertSame('123', $modified->key);
    }

    public function testInvalidSettingsAreRejected(): void
    {
        foreach ([
            static fn () => Backoff::exponential(after: 0),
            static fn () => Backoff::exponential(initialDelay: 0),
            static fn () => Backoff::exponential(initialDelay: 10, maxDelay: 5),
            static fn () => Backoff::exponential(maxDelay: 10, resetAfter: 9),
            static fn () => Backoff::exponential(
                maxDelay: intdiv(AdmissionPolicy::MAX_INTEGER, 1_000_000) + 1,
                resetAfter: intdiv(AdmissionPolicy::MAX_INTEGER, 1_000_000) + 1,
            ),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected an invalid rate limit exception.');
            } catch (InvalidRateLimitException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
