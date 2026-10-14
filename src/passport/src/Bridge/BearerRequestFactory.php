<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

class BearerRequestFactory
{
    protected readonly UriInterface $emptyUri;

    /**
     * Create a bearer request factory.
     */
    public function __construct(protected Psr17Factory $factory)
    {
        $this->emptyUri = $factory->createUri();
    }

    /**
     * Build the header-only request used for bearer token validation.
     */
    public function make(Request $request): ServerRequestInterface
    {
        // Bearer validation only reads Authorization; normalizing and parsing the URL is unnecessary.
        $psrRequest = $this->factory->createServerRequest($request->getMethod(), $this->emptyUri);
        $authorization = $request->headers->get('Authorization');

        return $authorization === null ? $psrRequest : $psrRequest->withHeader('Authorization', $authorization);
    }
}
