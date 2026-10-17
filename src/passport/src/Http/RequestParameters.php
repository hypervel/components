<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http;

use Hypervel\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;

class RequestParameters
{
    protected const int MAX_ENCODED_LENGTH = 65536;

    /**
     * Read OAuth parameters without losing repeated resource indicators or duplicate scalar fields.
     *
     * @return array<string, list<string>|string>
     */
    public static function fromRequest(Request $request): array
    {
        $query = $request->isMethod('GET');
        $encoded = $query ? $request->server->get('QUERY_STRING', '') : $request->getContent();

        if ($encoded !== '') {
            return static::parse($encoded);
        }

        // Constructed HTTP requests can carry parsed input without a raw query or body.
        $parameters = $query ? $request->query->all() : $request->request->all();

        foreach ($parameters as $name => $value) {
            if ($name === 'resource' && is_array($value) && array_is_list($value)) {
                foreach ($value as $resource) {
                    if (! is_string($resource)) {
                        throw OAuthServerException::invalidRequest('resource');
                    }
                }
            } elseif (is_string($value) || is_numeric($value)) {
                $parameters[$name] = $name === 'resource' ? [(string) $value] : (string) $value;
            } elseif ($value === null) {
                unset($parameters[$name]);
            } else {
                throw OAuthServerException::invalidRequest((string) $name);
            }
        }

        return $parameters;
    }

    /**
     * Parse a query string or form body using OAuth's repeated-parameter rules.
     *
     * @return array<string, list<string>|string>
     */
    public static function parse(string $encoded): array
    {
        if (strlen($encoded) > self::MAX_ENCODED_LENGTH) {
            throw OAuthServerException::invalidRequest('request', 'The OAuth request is too large. Use a pushed authorization request.');
        }

        $parameters = [];

        foreach (explode('&', $encoded) as $field) {
            if ($field === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $field, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);

            if ($name === 'resource') {
                $parameters[$name][] = $value;
            } elseif (array_key_exists($name, $parameters) || str_contains($name, '[') || str_contains($name, ']')) {
                throw OAuthServerException::invalidRequest($name);
            } else {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Encode protocol parameters without PHP's bracket notation for repeated resources.
     *
     * @param array<string, list<string>|string> $parameters
     */
    public static function toQuery(array $parameters): string
    {
        $fields = [];

        foreach ($parameters as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $fields[] = urlencode($name) . '=' . urlencode($item);
            }
        }

        return implode('&', $fields);
    }
}
