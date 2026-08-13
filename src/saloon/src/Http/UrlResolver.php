<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use GuzzleHttp\Psr7\Uri;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Psr\Http\Message\UriInterface;

final class UrlResolver
{
    /**
     * Resolve the connector base URL and request endpoint.
     */
    public static function resolve(string $baseUrl, string $endpoint, bool $allowBaseUrlOverride): UriInterface
    {
        $base = new Uri($baseUrl);
        $endpointUri = new Uri($endpoint);

        if ($baseUrl !== '') {
            self::ensureAbsoluteHttpUri($base, 'connector base URL');
        }

        if ($endpointUri->getScheme() !== '' || $endpointUri->getHost() !== '') {
            self::ensureAbsoluteHttpUri($endpointUri, 'request endpoint');

            if ($baseUrl !== '' && ! $allowBaseUrlOverride) {
                throw new PendingRequestException('The request endpoint cannot replace the connector base URL.');
            }

            return $endpointUri;
        }

        if ($baseUrl === '') {
            throw new PendingRequestException('A request without a connector base URL must use an absolute HTTP or HTTPS endpoint.');
        }

        $basePath = rtrim($base->getPath(), '/');
        $endpointPath = $endpointUri->getPath();
        $path = $endpointPath === ''
            ? $basePath
            : $basePath . '/' . ltrim($endpointPath, '/');
        $query = implode('&', array_filter(
            [$base->getQuery(), $endpointUri->getQuery()],
            static fn (string $part): bool => $part !== '',
        ));

        return $base
            ->withPath($path)
            ->withQuery($query)
            ->withFragment($endpointUri->getFragment());
    }

    /**
     * Merge repository values into the URI query.
     *
     * @param array<array-key, mixed> $parameters
     */
    public static function withQuery(UriInterface $uri, array $parameters): UriInterface
    {
        if ($parameters === []) {
            return $uri;
        }

        $parameters = StructuredDataNormalizer::forUrlEncoding($parameters);
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($parameters));
        $pairs = $uri->getQuery() === '' ? [] : explode('&', $uri->getQuery());
        $retained = [];

        foreach ($pairs as $pair) {
            $encodedName = explode('=', $pair, 2)[0];
            $name = urldecode($encodedName);

            foreach ($keys as $key) {
                if ($name === $key || str_starts_with($name, $key . '[')) {
                    continue 2;
                }
            }

            $retained[] = $pair;
        }

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        if ($query !== '') {
            $retained[] = $query;
        }

        return $uri->withQuery(implode('&', $retained));
    }

    /**
     * Ensure the URI is an absolute HTTP or HTTPS URI.
     */
    private static function ensureAbsoluteHttpUri(UriInterface $uri, string $name): void
    {
        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new PendingRequestException("The {$name} must be an absolute HTTP or HTTPS URI.");
        }
    }
}
