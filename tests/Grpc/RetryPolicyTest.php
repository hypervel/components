<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class RetryPolicyTest extends TestCase
{
    public function testStoresAValidatedRetryPolicy(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 4,
            initialBackoff: 0.25,
            maxBackoff: 3.0,
            backoffMultiplier: 1.5,
            retryableStatusCodes: [StatusCode::Unavailable, StatusCode::Aborted],
        );

        $this->assertSame(4, $policy->maxAttempts);
        $this->assertSame(0.25, $policy->initialBackoff);
        $this->assertSame(3.0, $policy->maxBackoff);
        $this->assertSame(1.5, $policy->backoffMultiplier);
        $this->assertSame(
            [StatusCode::Unavailable, StatusCode::Aborted],
            $policy->retryableStatusCodes,
        );
    }

    public function testUsesProtocolDefaults(): void
    {
        $policy = new RetryPolicy(maxAttempts: 2);

        $this->assertSame(0.1, $policy->initialBackoff);
        $this->assertSame(5.0, $policy->maxBackoff);
        $this->assertSame(2.0, $policy->backoffMultiplier);
        $this->assertSame([StatusCode::Unavailable], $policy->retryableStatusCodes);
    }

    public function testRejectsInvalidAttemptAndBackoffValues(): void
    {
        $policies = [
            static fn () => new RetryPolicy(maxAttempts: 1),
            static fn () => new RetryPolicy(maxAttempts: 2, initialBackoff: 0),
            static fn () => new RetryPolicy(maxAttempts: 2, initialBackoff: INF),
            static fn () => new RetryPolicy(maxAttempts: 2, maxBackoff: 0),
            static fn () => new RetryPolicy(maxAttempts: 2, maxBackoff: NAN),
            static fn () => new RetryPolicy(maxAttempts: 2, initialBackoff: 2, maxBackoff: 1),
            static fn () => new RetryPolicy(maxAttempts: 2, backoffMultiplier: 0.9),
            static fn () => new RetryPolicy(maxAttempts: 2, backoffMultiplier: INF),
        ];

        foreach ($policies as $policy) {
            try {
                $policy();
                $this->fail('Expected the retry policy to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsEmptyNonListDuplicateAndOkStatusSets(): void
    {
        $policies = [
            static fn () => new RetryPolicy(maxAttempts: 2, retryableStatusCodes: []),
            static fn () => new RetryPolicy(
                maxAttempts: 2,
                retryableStatusCodes: [1 => StatusCode::Unavailable],
            ),
            static fn () => new RetryPolicy(
                maxAttempts: 2,
                retryableStatusCodes: [StatusCode::Unavailable, StatusCode::Unavailable],
            ),
            static fn () => new RetryPolicy(
                maxAttempts: 2,
                retryableStatusCodes: [StatusCode::Ok],
            ),
            static fn () => new RetryPolicy(
                maxAttempts: 2,
                retryableStatusCodes: ['unavailable'],
            ),
        ];

        foreach ($policies as $policy) {
            try {
                $policy();
                $this->fail('Expected the retryable status set to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
