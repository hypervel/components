<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Exceptions;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class HttpResponseException extends RuntimeException
{
    public function __construct(protected ResponseInterface $response)
    {
    }

    /**
     * Get the underlying response instance.
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
