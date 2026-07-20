<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Coroutine;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Foundation\Testing\Coroutine\Waiter;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use Hypervel\Tests\TestCase;
use RuntimeException;

class WaiterTest extends TestCase
{
    public function testWaitCopiesContextAndReturnsAfterTheChildExits(): void
    {
        CoroutineContext::set('request_id', 'request-value');
        $childCoroutineId = null;

        $result = (new Waiter)->wait(function () use (&$childCoroutineId): mixed {
            $childCoroutineId = Coroutine::id();

            return CoroutineContext::get('request_id');
        });

        $this->assertSame('request-value', $result);
        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testContextReplicationFailureIsReportedInsteadOfTimingOut(): void
    {
        CoroutineContext::set('throwing', new ThrowingReplicableContext);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to replicate context.');

        (new Waiter(0.01))->wait(static fn (): string => 'never');
    }

    public function testTimeoutCancelsTheChildCoroutine(): void
    {
        $childCoroutineId = null;
        $waiter = new class(0.001) extends Waiter {
            protected float $pushTimeout = 0.01;
        };

        try {
            $waiter->wait(function () use (&$childCoroutineId): void {
                $childCoroutineId = Coroutine::id();
                Coroutine::sleep(0.1);
            });
            $this->fail('The waiter should time out.');
        } catch (WaitTimeoutException) {
        }

        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }
}
