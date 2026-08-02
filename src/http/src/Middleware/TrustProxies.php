<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class TrustProxies
{
    /**
     * The trusted proxies for the application.
     *
     * @var null|array<int, string>|string
     */
    protected array|string|null $proxies = null;

    /**
     * The trusted proxies headers for the application.
     */
    protected int|string $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * The proxies that have been configured to always be trusted.
     *
     * @var null|array<int, string>|string
     */
    protected static array|string|null $alwaysTrustProxies = null;

    /**
     * The proxies headers that have been configured to always be trusted.
     */
    protected static ?int $alwaysTrustHeaders = null;

    /**
     * Handle an incoming request.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request::setTrustedProxies([], $this->getTrustedHeaderNames());

        $this->setTrustedProxyIpAddresses($request);

        return $next($request);
    }

    /**
     * Set the trusted proxies on the request.
     */
    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $trustedIps = $this->proxies();

        // Laravel Cloud, Forge, and Vapor proxy discovery has no Hypervel equivalent.

        if ($trustedIps === '*' || $trustedIps === '**') {
            $this->setTrustedProxyIpAddressesToTheCallingIp($request);

            return;
        }

        $trustedIps = is_string($trustedIps)
            ? array_map(trim(...), explode(',', $trustedIps))
            : $trustedIps;

        if (is_array($trustedIps)) {
            $this->setTrustedProxyIpAddressesToSpecificIps($request, $trustedIps);
        }
    }

    /**
     * Specify the IP addresses to trust explicitly.
     */
    protected function setTrustedProxyIpAddressesToSpecificIps(Request $request, array $trustedIps): void
    {
        $request->setTrustedProxies(array_reduce($trustedIps, function ($ips, $trustedIp) use ($request) {
            $ips[] = $trustedIp === 'REMOTE_ADDR'
                ? $request->server->get('REMOTE_ADDR')
                : $trustedIp;

            return $ips;
        }, []), $this->getTrustedHeaderNames());
    }

    /**
     * Set the trusted proxies to catchall addresses for IPv4 and IPv6.
     */
    protected function setTrustedProxyIpAddressesToTheCallingIp(Request $request): void
    {
        $request->setTrustedProxies(['0.0.0.0/0', '::/0'], $this->getTrustedHeaderNames());
    }

    /**
     * Resolve the trusted-header bitmask.
     *
     * @return int a bit field of Request::HEADER_*, to set which headers to trust from your proxies
     */
    protected function getTrustedHeaderNames(): int
    {
        $headers = $this->headers();

        if (is_int($headers)) {
            return $headers;
        }

        return match ($headers) {
            'HEADER_X_FORWARDED_AWS_ELB' => Request::HEADER_X_FORWARDED_AWS_ELB,
            'HEADER_FORWARDED' => Request::HEADER_FORWARDED,
            'HEADER_X_FORWARDED_FOR' => Request::HEADER_X_FORWARDED_FOR,
            'HEADER_X_FORWARDED_HOST' => Request::HEADER_X_FORWARDED_HOST,
            'HEADER_X_FORWARDED_PORT' => Request::HEADER_X_FORWARDED_PORT,
            'HEADER_X_FORWARDED_PROTO' => Request::HEADER_X_FORWARDED_PROTO,
            'HEADER_X_FORWARDED_PREFIX' => Request::HEADER_X_FORWARDED_PREFIX,
            'HEADER_X_FORWARDED_ALL' => Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB,
            'HEADER_X_FORWARDED_TRAEFIK' => Request::HEADER_X_FORWARDED_TRAEFIK,
            default => throw new InvalidArgumentException("Unsupported trusted header configuration [{$headers}]."),
        };
    }

    /**
     * Get the trusted headers.
     */
    protected function headers(): int|string
    {
        return static::$alwaysTrustHeaders ?? $this->headers;
    }

    /**
     * Get the trusted proxies.
     */
    protected function proxies(): array|string|null
    {
        return static::$alwaysTrustProxies ?? $this->proxies;
    }

    /**
     * Specify the IP addresses of proxies that should always be trusted.
     *
     * Boot-only. The list persists in a static property for the worker lifetime
     * and applies to every subsequent request.
     */
    public static function at(array|string $proxies): void
    {
        static::$alwaysTrustProxies = $proxies;
    }

    /**
     * Specify the proxy headers that should always be trusted.
     *
     * Boot-only. The header set persists in a static property for the worker
     * lifetime and applies to every subsequent request.
     */
    public static function withHeaders(int $headers): void
    {
        static::$alwaysTrustHeaders = $headers;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$alwaysTrustHeaders = null;
        static::$alwaysTrustProxies = null;
    }
}
