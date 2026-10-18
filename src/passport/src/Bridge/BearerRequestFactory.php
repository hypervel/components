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
     * Build a resource request, including the HTTP target only for DPoP.
     */
    public function make(Request $request): ServerRequestInterface
    {
        // Bearer validation only reads Authorization; normalizing and parsing the URL is unnecessary.
        $authorization = $request->headers->get('Authorization');
        $dpop = (static::credentials($authorization ?? '')['scheme'] ?? null) === 'DPoP';
        $psrRequest = $this->factory->createServerRequest(
            $request->getMethod(),
            $dpop ? $request->getUri() : $this->emptyUri,
        );

        if ($dpop && $request->headers->has('DPoP')) {
            $psrRequest = $psrRequest->withHeader('DPoP', $request->headers->all('DPoP'));
        }

        return $authorization === null ? $psrRequest : $psrRequest->withHeader('Authorization', $authorization);
    }

    /**
     * Read a supported access-token scheme without interpreting the token itself.
     *
     * @return null|array{scheme: string, token: string}
     */
    public static function credentials(string $authorization): ?array
    {
        if (preg_match('/^\s*(Bearer|DPoP)(?:\s+(.*))?$/iD', $authorization, $matches) !== 1) {
            return null;
        }

        return [
            'scheme' => strcasecmp($matches[1], 'DPoP') === 0 ? 'DPoP' : 'Bearer',
            'token' => trim($matches[2] ?? ''),
        ];
    }
}
