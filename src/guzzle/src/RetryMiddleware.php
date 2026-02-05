<?php

declare(strict_types=1);

namespace Hypervel\Guzzle;

use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RetryMiddleware implements MiddlewareInterface
{
    /**
     * Create a new retry middleware instance.
     */
    public function __construct(
        protected int $retries = 1,
        protected int $delay = 0,
    ) {
    }

    /**
     * Get the middleware callable.
     */
    public function getMiddleware(): callable
    {
        return Middleware::retry(function ($retries, RequestInterface $request, ?ResponseInterface $response = null) {
            if (! $this->isOk($response) && $retries < $this->retries) {
                return true;
            }
            return false;
        }, function () {
            return $this->delay;
        });
    }

    /**
     * Check if the response status is successful.
     */
    protected function isOk(?ResponseInterface $response): bool
    {
        return $response && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }
}
