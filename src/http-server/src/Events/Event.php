<?php

declare(strict_types=1);

namespace Hypervel\HttpServer\Events;

use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

abstract class Event
{
    public function __construct(
        public ?Request $request,
        public ?Response $response,
        public ?Throwable $exception = null,
        public string $server = 'http'
    ) {
    }

    /**
     * Get the exception that occurred during the request, if any.
     */
    public function getThrowable(): ?Throwable
    {
        return $this->exception;
    }
}
