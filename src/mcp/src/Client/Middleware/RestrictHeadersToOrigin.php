<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Middleware;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriComparator;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Keep custom credentials on their configured origin while still following redirects.
 *
 * Guzzle strips Authorization and Cookie, but other headers can also carry credentials.
 * Laravel MCP forwards those headers; redirects remain supported here without exposing them.
 */
class RestrictHeadersToOrigin
{
    protected readonly UriInterface $origin;

    /**
     * Create middleware for the configured endpoint's custom headers.
     *
     * @param list<string> $headers
     */
    public function __construct(string $url, protected readonly array $headers)
    {
        $this->origin = new Uri($url);
    }

    /**
     * Remove custom headers before a request leaves their origin.
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if (UriComparator::isCrossOrigin($this->origin, $request->getUri())) {
                foreach ($this->headers as $name) {
                    $request = $request->withoutHeader($name);
                }
            }

            return $handler($request, $options);
        };
    }
}
