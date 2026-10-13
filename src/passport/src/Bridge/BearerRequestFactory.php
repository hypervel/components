<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

class BearerRequestFactory
{
    /**
     * Create a bearer request factory.
     */
    public function __construct(protected Psr17Factory $factory)
    {
    }

    /**
     * Build the header-only request used for bearer token validation.
     */
    public function make(Request $request): ServerRequestInterface
    {
        $psrRequest = $this->factory->createServerRequest($request->getMethod(), $request->getUri());
        $authorization = $request->headers->get('Authorization');

        return $authorization === null ? $psrRequest : $psrRequest->withHeader('Authorization', $authorization);
    }
}
