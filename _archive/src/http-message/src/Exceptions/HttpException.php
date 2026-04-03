<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Exceptions;

use Hypervel\HttpMessage\Base\Response;
use RuntimeException;
use Throwable;

class HttpException extends RuntimeException
{
    /**
     * Create a new HTTP exception instance.
     */
    public function __construct(
        public int $statusCode,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected array $headers = [],
    ) {
        if ($message === '') {
            $message = Response::getReasonPhraseByCode($statusCode);
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the response headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get the user-friendly name of this exception.
     */
    public function getName(): string
    {
        $message = Response::getReasonPhraseByCode($this->statusCode);
        if (! $message) {
            $message = 'Error';
        }
        return $message;
    }
}
