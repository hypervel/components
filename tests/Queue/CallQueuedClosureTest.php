<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Tests\TestCase;
use Throwable;

class CallQueuedClosureTest extends TestCase
{
    public function testManualFailureWithoutAnExceptionInvokesTheCallback(): void
    {
        $failures = [];
        $job = CallQueuedClosure::create(static function (): void {
        })->onFailure(function (?Throwable $exception) use (&$failures): void {
            $failures[] = $exception;
        });

        $job->failed(null);

        $this->assertSame([null], $failures);
    }
}
