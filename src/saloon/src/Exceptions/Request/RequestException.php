<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions\Request;

use Hypervel\Http\Client\Concerns\DeterminesStatusCode;
use Hypervel\Http\Client\RequestException as HttpRequestException;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;

class RequestException extends HttpRequestException
{
    use DeterminesStatusCode;

    /**
     * Get the Saloon response.
     */
    public function response(): Response
    {
        /** @var Response $response */
        $response = $this->response;

        return $response;
    }

    /**
     * Get the pending request.
     */
    public function pendingRequest(): PendingRequest
    {
        return $this->response()->pendingRequest();
    }

    /**
     * Get the HTTP status code.
     */
    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * Get the response body.
     */
    public function body(): string
    {
        return $this->response->body();
    }
}
