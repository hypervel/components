<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Fixtures;

use Closure;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Queue\Queueable;

class FakeSqsJobWithMessageGroup implements ShouldQueue
{
    use Queueable;

    protected static ?Closure $messageGroupFactory = null;

    public function handle(): void
    {
    }

    /**
     * Message group method called by SqsQueue.
     */
    public function messageGroup(): string
    {
        return static::$messageGroupFactory
            ? (string) call_user_func(static::$messageGroupFactory)
            : 'group-1';
    }

    /**
     * Set the callable that will be used to generate message groups.
     */
    public static function createMessageGroupsUsing(?callable $factory = null): void
    {
        static::$messageGroupFactory = $factory === null ? null : Closure::fromCallable($factory);
    }

    /**
     * Indicate that message groups should be created normally and not using a custom factory.
     */
    public static function createMessageGroupsNormally(): void
    {
        static::$messageGroupFactory = null;
    }
}
