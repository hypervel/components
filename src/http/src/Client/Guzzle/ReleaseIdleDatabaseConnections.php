<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Database\ConnectionResolver;
use Psr\Http\Message\RequestInterface;

class ReleaseIdleDatabaseConnections
{
    /**
     * Return idle database sessions before waiting for an external service.
     */
    public function __invoke(callable $handler): Closure
    {
        return static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            ConnectionResolver::releaseIdleConnections();

            return $handler($request, $options);
        };
    }
}
