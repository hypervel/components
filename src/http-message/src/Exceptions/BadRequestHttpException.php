<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Exceptions;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class BadRequestHttpException extends HttpException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected ?ServerRequestInterface $request = null,
        array $headers = [],
    ) {
        parent::__construct(400, $message, $code, $previous, $headers);
    }

    /**
     * Set the request that caused the exception.
     */
    public function setRequest(?ServerRequestInterface $request): static
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Get the request that caused the exception.
     */
    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }
}
