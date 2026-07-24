<?php

declare(strict_types=1);

namespace Hypervel\HttpServer\Events;

use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Base event for HTTP server request lifecycle observations.
 *
 * The exception slot contains failures that escape the kernel or occur during
 * transport lifecycle work. Application exceptions rendered by the kernel are
 * reported through the exception handler and remain on a Hypervel response's
 * exception property.
 */
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
     * Get the transport or lifecycle exception, if any.
     */
    public function getThrowable(): ?Throwable
    {
        return $this->exception;
    }
}
