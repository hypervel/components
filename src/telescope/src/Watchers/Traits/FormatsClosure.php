<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers\Traits;

use Closure;
use ReflectionException;
use ReflectionFunction;

trait FormatsClosure
{
    /**
     * Format a closure-based listener.
     *
     * @throws ReflectionException
     */
    protected function formatClosureListener(Closure $listener): string
    {
        $listener = new ReflectionFunction($listener);

        return sprintf(
            'Closure at %s[%s:%s]',
            $listener->getFileName(),
            $listener->getStartLine(),
            $listener->getEndLine()
        );
    }
}
