<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Support\Facades\Http;

/**
 * Requests are made from this class so the origin tests can assert a known application call site.
 */
class HttpClientOriginFixture
{
    /**
     * Send a request.
     */
    public function get(string $url): void
    {
        Http::get($url);
    }

    /**
     * Start an asynchronous request that its caller waits on later.
     */
    public function getAsync(string $url): PromiseInterface
    {
        return Http::async()->get($url);
    }
}
