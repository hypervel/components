<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class RetryTest extends TestCase
{
    public function testOrdinaryFailureCanBeRetried(): void
    {
        $attempts = 0;

        $result = retry(2, function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new RuntimeException('try again');
            }

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(2, $attempts);
    }

    public function testCancellationIsNotRetriedOrPassedToRetryPolicy(): void
    {
        $cancellation = new CanceledException('operation cancelled');
        $attempts = 0;
        $policyCalled = false;
        $sleepCalled = false;

        try {
            retry(
                3,
                function () use (&$attempts, $cancellation): never {
                    ++$attempts;

                    throw $cancellation;
                },
                function () use (&$sleepCalled): int {
                    $sleepCalled = true;

                    return 1;
                },
                function () use (&$policyCalled): bool {
                    $policyCalled = true;

                    return true;
                },
            );
            $this->fail('Expected the operation cancellation to be thrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $attempts);
        $this->assertFalse($policyCalled);
        $this->assertFalse($sleepCalled);
    }
}
