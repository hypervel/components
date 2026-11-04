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
 * Keep custom credentials removed after a redirect leaves their configured origin.
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
     * Remove custom headers for the rest of a cross-origin redirect chain.
     */
    public function __invoke(callable $handler): Closure
    {
        // Callers create a fresh pending request per operation, so this state belongs to one redirect chain.
        $leftOrigin = false;

        return function (RequestInterface $request, array $options) use ($handler, &$leftOrigin): PromiseInterface {
            $leftOrigin = $leftOrigin || UriComparator::isCrossOrigin($this->origin, $request->getUri());

            if ($leftOrigin) {
                foreach ($this->headers as $name) {
                    $request = $request->withoutHeader($name);
                }
            }

            return $handler($request, $options);
        };
    }
}
