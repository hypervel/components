<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response;

class InvalidApiKeyException extends RequestException
{
    /**
     * Create a new invalid API key exception.
     */
    public function __construct(public string $provider, Response $response, false|int|null $truncateExceptionsAt = null)
    {
        parent::__construct($response, $truncateExceptionsAt);
    }

    /**
     * Prepare the exception message.
     */
    protected function prepareMessage(Response $response): string
    {
        return "Missing or invalid API key for AI provider [{$this->provider}]. " . parent::prepareMessage($response);
    }
}
