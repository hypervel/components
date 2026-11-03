<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

use Throwable;

/**
 * @internal
 */
class ToolExecutionException extends AiException
{
    /**
     * Create a new tool execution exception wrapping the tool's own failure.
     */
    public function __construct(public readonly Throwable $exception)
    {
        parent::__construct($exception->getMessage(), previous: $exception);
    }
}
