<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\UriTemplate\UriTemplate;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Psr\Http\Message\UriInterface;

final class UrlResolver
{
    /**
     * Substitute the URL parameters in the given URL.
     *
     * @param array<array-key, mixed> $parameters
     */
    public static function expand(string $url, array $parameters): string
    {
        if ($parameters === [] || ! str_contains($url, '{')) {
            return $url;
        }

        return UriTemplate::expand($url, $parameters);
    }

    /**
     * Resolve the connector base URL and request endpoint.
     */
    public static function resolve(string $baseUrl, string $endpoint, bool $allowBaseUrlOverride): UriInterface
    {
        $absolute = preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://~', $endpoint) === 1;

        // Only a scheme followed by "//" names another host. Other endpoints are paths, so they are parsed with one
        // leading slash: URI parsing would read "documents:batchGet" as a scheme and "//users" as a host.
        if (! $absolute && $endpoint !== '' && ! str_starts_with($endpoint, '?') && ! str_starts_with($endpoint, '#')) {
            $endpoint = '/' . ltrim($endpoint, '/');
        }

        $base = new Uri($baseUrl);
        $endpointUri = new Uri($endpoint);

        if ($baseUrl !== '') {
            self::ensureAbsoluteHttpUri($base, 'connector base URL');
        }

        if ($absolute) {
            self::ensureAbsoluteHttpUri($endpointUri, 'request endpoint');

            if ($baseUrl !== '' && ! $allowBaseUrlOverride) {
                throw new PendingRequestException('The request endpoint cannot replace the connector base URL. To request a different host, use a connector with that host as its base URL, or return true from allowsBaseUrlOverride() on the connector or request when the endpoint is trusted.');
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
            if ($pair === '') {
                continue;
            }

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
