<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bus;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Support\Fluent;
use Hypervel\Tests\TestCase;

class DispatchableTest extends TestCase
{
    public function testPendingDispatchFactoryIsUsedByEveryConditionalSuccessBranch(): void
    {
        CustomPendingDispatchJob::$pendingDispatches = [];

        $pendingDispatches = [
            CustomPendingDispatchJob::dispatch('dispatch'),
            CustomPendingDispatchJob::dispatchIf(true, 'dispatch-if-boolean'),
            CustomPendingDispatchJob::dispatchIf(static fn (CustomPendingDispatchJob $job): bool => $job->value !== '', 'dispatch-if-closure'),
            CustomPendingDispatchJob::dispatchUnless(false, 'dispatch-unless-boolean'),
            CustomPendingDispatchJob::dispatchUnless(static fn (CustomPendingDispatchJob $job): bool => $job->value === '', 'dispatch-unless-closure'),
        ];

        foreach ($pendingDispatches as $pendingDispatch) {
            $this->assertInstanceOf(CustomPendingDispatch::class, $pendingDispatch);
        }

        $this->assertSame([
            'dispatch',
            'dispatch-if-boolean',
            'dispatch-if-closure',
            'dispatch-unless-boolean',
            'dispatch-unless-closure',
        ], array_map(
            static fn (CustomPendingDispatchJob $job): string => $job->value,
            CustomPendingDispatchJob::$pendingDispatches,
        ));

        $this->assertInstanceOf(Fluent::class, CustomPendingDispatchJob::dispatchIf(false));
        $this->assertInstanceOf(Fluent::class, CustomPendingDispatchJob::dispatchUnless(true));
        $this->assertCount(5, CustomPendingDispatchJob::$pendingDispatches);
    }
}

class CustomPendingDispatchJob
{
    use Dispatchable;

    /** @var list<self> */
    public static array $pendingDispatches = [];

    public function __construct(public string $value = '')
    {
    }

    protected static function newPendingDispatch(mixed $job): PendingDispatch
    {
        static::$pendingDispatches[] = $job;

        return new CustomPendingDispatch($job);
    }
}

class CustomPendingDispatch extends PendingDispatch
{
    public function __destruct()
    {
    }
}
