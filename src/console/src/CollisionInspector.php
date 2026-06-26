<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Throwable;
use Whoops\Exception\Inspector as BaseInspector;

final class CollisionInspector extends BaseInspector
{
    /**
     * Get the backtrace from an exception.
     *
     * @param Throwable $exception
     * @return array<int, array<string, mixed>>
     */
    protected function getTrace($exception)
    {
        return $exception->getTrace();
    }
}
