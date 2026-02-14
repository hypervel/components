<?php

declare(strict_types=1);

namespace Hypervel\HttpServer\Events;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

abstract class Event
{
    public function __construct(
        public ?ServerRequestInterface $request,
        public ?ResponseInterface $response,
        public ?Throwable $exception = null,
        public string $server = 'http'
    ) {
    }

    public function getThrowable(): ?Throwable
    {
        return $this->exception;
    }
}
