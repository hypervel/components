<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Support\Str;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcherInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class HypervelRequestFetcher implements RequestFetcherInterface
{
    /**
     * Cached PSR-7 factory for converting Symfony requests.
     *
     * Stateless and safe for worker-lifetime caching — all properties are readonly.
     */
    private static ?PsrHttpFactory $psrHttpFactory = null;

    /**
     * Fetch the current request as a PSR-7 server request for Sentry.
     */
    public function fetchRequest(): ?ServerRequestInterface
    {
        if (! RequestContext::has()) {
            return null;
        }

        $httpFoundationRequest = RequestContext::get();

        $request = self::getPsrHttpFactory()->createRequest($httpFoundationRequest);

        return $request->withCookieParams(
            $this->filterCookies($request->getCookieParams())
        );
    }

    /**
     * Filter sensitive cookies before sending to Sentry.
     */
    protected function filterCookies(array $cookies): array
    {
        $forbiddenCookies = [config('session.cookie'), 'remember_*', 'XSRF-TOKEN'];

        $filtered = [];
        foreach ($cookies as $key => $value) {
            $filtered[$key] = Str::is($forbiddenCookies, $key) ? '[Filtered]' : $value;
        }

        return $filtered;
    }

    /**
     * Get or create the PSR-7 factory.
     */
    private static function getPsrHttpFactory(): PsrHttpFactory
    {
        if (self::$psrHttpFactory === null) {
            $psr17Factory = new Psr17Factory;
            self::$psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
        }

        return self::$psrHttpFactory;
    }
}
