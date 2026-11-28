<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

use Hypervel\Ai\Responses\Data\TextUsage;

class ProviderResponseException extends AiException
{
    /**
     * Create an exception for a failed provider response with its reported token usage.
     */
    public function __construct(string $message, int $code = 0, public readonly ?TextUsage $usage = null)
    {
        parent::__construct($message, $code);
    }
}
