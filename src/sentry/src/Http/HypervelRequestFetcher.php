<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Http;

use Hypervel\Context\RequestContext;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\SentrySdk;
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
        // If there is no request in the current coroutine we are not dealing
        // with a HTTP request and there is no request to fetch for us so we
        // can exit early.
        if (! RequestContext::has()) {
            return null;
        }

        // Converted only when an event needs it, instead of caching a PSR-7 copy of every
        // request in the worker-shared container as upstream's SetRequestMiddleware does
        $request = self::getPsrHttpFactory()->createRequest(RequestContext::get());

        if (DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub())->isLegacyMode()) {
            $request = $this->withoutEmptyParsedBody($request);
        } else {
            // The PSR-7 bridge already builds the URI from the query string as it was received
            $request = $this->withoutParsedBodyForNonFormRequests($request);
        }

        $cookies = [];

        foreach ($request->getCookieParams() as $name => $value) {
            $cookies[$name] = SensitiveCookieFilter::filterValue($name, $value);
        }

        return $request->withCookieParams($cookies);
    }

    /**
     * Drop an empty parsed body from requests that are not forms.
     *
     * The PSR-7 request has an empty parsed body for all requests that are not forms, which would
     * hide the raw body from being read.
     */
    private function withoutEmptyParsedBody(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getParsedBody() !== [] || $this->isFormRequest($request)) {
            return $request;
        }

        return $request->withParsedBody(null);
    }

    /**
     * Drop the parsed body from requests that are not forms.
     *
     * Only form requests use their parsed body, so all other bodies are read raw.
     */
    private function withoutParsedBodyForNonFormRequests(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($this->isFormRequest($request)) {
            return $request;
        }

        return $request->withParsedBody(null);
    }

    /**
     * Determine if the request body is a form.
     */
    private function isFormRequest(ServerRequestInterface $request): bool
    {
        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

        return $mediaType === 'application/x-www-form-urlencoded' || $mediaType === 'multipart/form-data';
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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$psrHttpFactory = null;
    }
}
