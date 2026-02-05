<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Exception;

use Throwable;

/**
 * Wrapper for passing exceptions through channels.
 *
 * Since exceptions cannot be pushed directly to channels, this class
 * wraps a Throwable so it can be passed through and re-thrown later.
 */
final class ExceptionThrower
{
    public function __construct(
        private readonly Throwable $throwable,
    ) {
    }

    /**
     * Get the wrapped throwable.
     */
    public function getThrowable(): Throwable
    {
        return $this->throwable;
    }
}
