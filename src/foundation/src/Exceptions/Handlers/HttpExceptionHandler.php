<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Handlers;

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\HttpMessage\Exceptions\HttpResponseException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class HttpExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if ($throwable instanceof HttpResponseException) {
            return $throwable->getResponse();
        }

        foreach ($this->getHeaders($throwable) as $key => $value) {
            $response = $response->withHeader($key, $value);
        }

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }

    protected function getHeaders(Throwable $e): array
    {
        return $e instanceof HttpException ? $e->getHeaders() : [];
    }
}
