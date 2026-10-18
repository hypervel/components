<?php

declare(strict_types=1);

namespace Hypervel\Support\Aws;

use Aws\CommandInterface;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Database\ConnectionResolver;
use Psr\Http\Message\RequestInterface;

/**
 * Return idle database sessions before an AWS client sends a command, as the HTTP client does before each request.
 *
 * Add it to a client's init step, so it runs before signing, which may
 * fetch credentials from a remote provider.
 */
class ReleaseIdleDatabaseConnections
{
    /**
     * Wrap the next handler in the client's handler list.
     */
    public function __invoke(callable $handler): Closure
    {
        return static function (CommandInterface $command, ?RequestInterface $request = null) use ($handler): PromiseInterface {
            ConnectionResolver::releaseIdleConnections();

            return $handler($command, $request);
        };
    }
}
