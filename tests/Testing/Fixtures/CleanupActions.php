<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Fixtures;

use Throwable;

class CleanupActions
{
    /**
     * Run every cleanup action and preserve the first failure.
     */
    public static function run(callable ...$actions): void
    {
        $exception = null;

        foreach ($actions as $action) {
            try {
                $action();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}
