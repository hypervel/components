<?php

declare(strict_types=1);

namespace Hypervel\Http\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HttpResponseException extends RuntimeException
{
    /**
     * Create a new HTTP response exception instance.
     */
    public function __construct(
        protected Response $response,
        ?Throwable $previous = null
    ) {
        parent::__construct($previous?->getMessage() ?? '', $previous?->getCode() ?? 0, $previous);
    }

    /**
     * Get the underlying response instance.
     */
    public function getResponse(): Response
    {
        return $this->response;
    }
}
