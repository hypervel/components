<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Fixtures;

use Closure;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Queueable;

/**
 * @internal
 * @coversNothing
 */
class FakeSqsJobWithDeduplication implements ShouldQueue
{
    use Queueable;

    protected static ?Closure $deduplicationIdFactory = null;

    public function handle(): void
    {
    }

    /**
     * Deduplication ID method called by SqsQueue.
     */
    public function deduplicationId(string $payload, string $queue): string
    {
        return static::$deduplicationIdFactory
            ? (string) call_user_func(static::$deduplicationIdFactory, $payload, $queue)
            : hash('sha256', json_encode(func_get_args()));
    }

    /**
     * Set the callable that will be used to generate deduplication IDs.
     */
    public static function createDeduplicationIdsUsing(?callable $factory = null): void
    {
        static::$deduplicationIdFactory = $factory;
    }

    /**
     * Indicate that deduplication IDs should be created normally and not using a custom factory.
     */
    public static function createDeduplicationIdsNormally(): void
    {
        static::$deduplicationIdFactory = null;
    }
}
