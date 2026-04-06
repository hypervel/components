<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integrations;

use Hypervel\Context\RequestContext;
use Hypervel\Support\Str;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcherInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class RequestFetcher implements RequestFetcherInterface
{
    /**
     * Fetch the current request as a PSR-7 server request for Sentry.
     */
    public function fetchRequest(): ?ServerRequestInterface
    {
        if (! RequestContext::has()) {
            return null;
        }

        $httpFoundationRequest = RequestContext::get();

        $psr17Factory = new Psr17Factory;
        $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);

        $request = $psrHttpFactory->createRequest($httpFoundationRequest);

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
}
