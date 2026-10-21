<?php

declare(strict_types=1);

namespace Hypervel\Sentinel\Drivers;

use Closure;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

abstract class Driver
{
    /**
     * Construct a new driver.
     *
     * @param Closure(): Application $applicationResolver
     */
    public function __construct(protected Closure $applicationResolver)
    {
    }

    /**
     * Authorize access for the request.
     */
    abstract public function authorize(Request $request): bool;

    /**
     * Authorize access for the request or throw an exception.
     *
     * @throws AuthorizationException
     */
    public function authorizeOrFail(Request $request): void
    {
        if ($this->authorize($request) === false) {
            throw new AuthorizationException;
        }
    }

    /**
     * Authorize accessing via reverse proxies.
     */
    protected function authorizeAccessingViaReverseProxies(Request $request): bool
    {
        if (! $this->isPrivateIp($request->ip()) && $request->isFromTrustedProxy()) {
            return false;
        }

        return true;
    }

    // Laravel's isRunningOnDockerLocally() is not ported. Running in Docker doesn't show that a request forwarded
    // by a loopback proxy came from the local machine, so it must not bypass the public-client check above.

    /**
     * Check if an IPv4 or IPv6 address is contained in the list of private IP subnets.
     */
    protected function isPrivateIp(string $requestIp): bool
    {
        return IpUtils::isPrivateIp($requestIp);
    }

    /**
     * Get the application instance.
     */
    protected function app(): Application
    {
        return ($this->applicationResolver)();
    }
}
