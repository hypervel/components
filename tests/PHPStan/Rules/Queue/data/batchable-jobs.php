<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Bus\Batchable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\Middleware\SkipIfBatchCancelled;

class BatchableNonQueuedClass
{
    use Batchable;

    public function handle(): void
    {
    }
}

abstract class AbstractBatchableJob implements ShouldQueue
{
    use Batchable;

    public function handle(): void
    {
    }
}

class BatchableJobWithCancellationCheck implements ShouldQueue
{
    use Batchable;
    use Dispatchable;

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
    }
}

class BatchableJobWithSkipMiddleware implements ShouldQueue
{
    use Batchable;

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled()];
    }

    public function handle(): void
    {
    }
}

class BatchableJobBase implements ShouldQueue
{
    use Batchable;

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
    }
}

class BatchableJobSubclass extends BatchableJobBase
{
}

class BatchableJobWithoutCancellationCheck implements ShouldQueue
{
    use Batchable;
    use Dispatchable;

    public function handle(): void
    {
    }
}

class ConcreteBatchableJobFromAbstractBase extends AbstractBatchableJob
{
}

abstract class AbstractBatchableJobWithMiddleware implements ShouldQueue
{
    use Batchable;

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled()];
    }
}

class JobInheritingCancellationMiddleware extends AbstractBatchableJobWithMiddleware
{
    public function handle(): void
    {
    }
}

trait CancellationMiddleware
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled()];
    }
}

class JobUsingCancellationMiddlewareTrait implements ShouldQueue
{
    use Batchable;
    use CancellationMiddleware;

    public function handle(): void
    {
    }
}

class JobOverridingCancellationGuard extends BatchableJobBase
{
    public function handle(): void
    {
    }
}

class JobOverridingCancellationMiddleware extends BatchableJobWithSkipMiddleware
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [];
    }
}

class JobOverridingHandleWithInheritedMiddleware extends AbstractBatchableJobWithMiddleware
{
    public function handle(): void
    {
    }
}

// Hypervel: checks are recognized by type, so a coincidental cancelled() on another object is not one.
class Order
{
    public function cancelled(): bool
    {
        return false;
    }
}

class JobCheckingAnotherCancellation implements ShouldQueue
{
    use Batchable;

    public function __construct(private Order $order)
    {
    }

    public function handle(): void
    {
        if ($this->order->cancelled()) {
            return;
        }
    }
}

class JobCheckingStoredBatch implements ShouldQueue
{
    use Batchable;

    public function handle(): void
    {
        $batch = $this->batch();

        if ($batch?->canceled()) {
            return;
        }
    }
}

class JobCheckingBatching implements ShouldQueue
{
    use Batchable;

    public function handle(): void
    {
        if (! $this->batching()) {
            return;
        }
    }
}

class JobNamingCancellationMiddleware implements ShouldQueue
{
    use Batchable;

    /** @return list<class-string> */
    public function middleware(): array
    {
        return [SkipIfBatchCancelled::class];
    }

    public function handle(): void
    {
    }
}

trait ChecksBatchCancellation
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
    }
}

class JobUsingCancellationCheckTrait implements ShouldQueue
{
    use Batchable;
    use ChecksBatchCancellation;
}
