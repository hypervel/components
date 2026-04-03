<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
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
    protected int $headers = Request::HEADER_X_FORWARDED_FOR
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
        $trustedIps = $this->proxies() ?: config('trustedproxy.proxies');

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
     * Set the trusted proxy to be the IP address calling this server.
     */
    protected function setTrustedProxyIpAddressesToTheCallingIp(Request $request): void
    {
        $request->setTrustedProxies([$request->server->get('REMOTE_ADDR')], $this->getTrustedHeaderNames());
    }

    /**
     * Retrieve trusted header name(s), falling back to defaults if config not set.
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
            default => Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB,
        };
    }

    /**
     * Get the trusted headers.
     */
    protected function headers(): int|string
    {
        return static::$alwaysTrustHeaders ?: $this->headers;
    }

    /**
     * Get the trusted proxies.
     */
    protected function proxies(): array|string|null
    {
        return static::$alwaysTrustProxies ?: $this->proxies;
    }

    /**
     * Specify the IP addresses of proxies that should always be trusted.
     */
    public static function at(array|string $proxies): void
    {
        static::$alwaysTrustProxies = $proxies;
    }

    /**
     * Specify the proxy headers that should always be trusted.
     */
    public static function withHeaders(int $headers): void
    {
        static::$alwaysTrustHeaders = $headers;
    }

    /**
     * Flush the state of the middleware.
     */
    public static function flushState(): void
    {
        static::$alwaysTrustHeaders = null;
        static::$alwaysTrustProxies = null;
    }
}
