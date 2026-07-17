<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Coroutine;

use Hypervel\Context\CoroutineContext;
use Hypervel\Foundation\Testing\Coroutine\Waiter;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use Hypervel\Tests\TestCase;
use RuntimeException;

class WaiterTest extends TestCase
{
    public function testContextReplicationFailureIsReportedInsteadOfTimingOut(): void
    {
        CoroutineContext::set('throwing', new ThrowingReplicableContext);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to replicate context.');

        (new Waiter(0.01))->wait(static fn (): string => 'never');
    }
}
