<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits;

use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\Response;

trait ManagesExceptions
{
    /**
     * Determine if the request failed.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return null;
    }

    /**
     * Resolve the request exception.
     */
    public function getRequestException(Response $response): ?RequestException
    {
        return null;
    }

    /**
     * Determine if the response should throw a request exception.
     */
    public function shouldThrowRequestException(Response $response): bool
    {
        return $response->failed();
    }
}
