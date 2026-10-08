<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

class NoSuchToolException extends AiException
{
    /**
     * Create an exception for an unavailable tool.
     */
    public function __construct(public readonly string $toolName)
    {
        parent::__construct(sprintf("Model tried to call unavailable tool '%s'.", $toolName));
    }
}
