<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http;

use Hypervel\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

class RequestParameters
{
    public const int MAX_ENCODED_LENGTH = 65536;

    /**
     * Preserve repeated protocol fields at PSR token and device endpoints.
     *
     * @return array<array-key, mixed>
     */
    public static function fromPsrRequest(ServerRequestInterface $request): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) === 'application/x-www-form-urlencoded') {
            $encoded = (string) $request->getBody();

            if ($encoded !== '') {
                return static::parse($encoded);
            }
        }

        return static::normalize((array) $request->getParsedBody());
    }

    /**
     * Read protocol parameters submitted as a form.
     *
     * @return array<array-key, list<string>|string>
     */
    public static function fromForm(Request $request): array
    {
        if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/x-www-form-urlencoded') {
            throw OAuthServerException::invalidRequest('request', 'OAuth POST requests must use form encoding.');
        }

        return static::fromRequest($request);
    }

    /**
     * Read OAuth parameters without losing repeated resource indicators or duplicate scalar fields.
     *
     * @return array<array-key, list<string>|string>
     */
    public static function fromRequest(Request $request): array
    {
        $query = $request->isMethod('GET');
        $encoded = $query ? $request->server->get('QUERY_STRING', '') : $request->getContent();

        if ($encoded !== '') {
            return static::parse($encoded);
        }

        // Constructed HTTP requests can carry parsed input without a raw query or body.
        $parameters = static::normalize($query ? $request->query->all() : $request->request->all());

        foreach ($parameters as $name => $value) {
            if ($name === 'resource' && is_array($value) && array_is_list($value)) {
                foreach ($value as $resource) {
                    if (! is_string($resource)) {
                        throw OAuthServerException::invalidRequest('resource');
                    }
                }
            } elseif (is_string($value) || is_numeric($value)) {
                $parameters[$name] = $name === 'resource' ? [(string) $value] : (string) $value;
            } else {
                throw OAuthServerException::invalidRequest((string) $name);
            }
        }

        return $parameters;
    }

    /**
     * Parse a query string or form body using OAuth's repeated-parameter rules.
     *
     * @return array<array-key, list<string>|string>
     */
    public static function parse(string $encoded): array
    {
        if (strlen($encoded) > self::MAX_ENCODED_LENGTH) {
            throw OAuthServerException::invalidRequest('request', 'The OAuth request is too large.');
        }

        $parameters = [];

        foreach (explode('&', $encoded) as $field) {
            if ($field === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $field, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);

            if (! mb_check_encoding($name, 'UTF-8')) {
                throw OAuthServerException::invalidRequest('request', 'OAuth parameters must use UTF-8.');
            }

            if ($name === 'resource') {
                $parameters[$name][] = $value;
            } elseif (array_key_exists($name, $parameters) || str_contains($name, '[') || str_contains($name, ']')) {
                throw OAuthServerException::invalidRequest($name);
            } else {
                $parameters[$name] = $value;
            }
        }

        return static::normalize($parameters);
    }

    /**
     * Validate protocol text and omit empty values after duplicate detection.
     *
     * @template TValue
     * @param array<array-key, TValue> $parameters
     * @return array<array-key, TValue>
     */
    protected static function normalize(array $parameters): array
    {
        foreach ($parameters as $name => $value) {
            if (! mb_check_encoding((string) $name, 'UTF-8')
                || (is_string($value) && ! mb_check_encoding($value, 'UTF-8'))
                || ($name === 'resource' && is_array($value) && ! mb_check_encoding($value, 'UTF-8'))) {
                throw OAuthServerException::invalidRequest('request', 'OAuth parameters must use UTF-8.');
            }

            if ($name === 'resource' && is_array($value) && array_is_list($value)) {
                $parameters[$name] = $value = array_values(array_filter($value, static fn (mixed $resource): bool => $resource !== ''));
            }

            // RFC 6749 treats an empty parameter as omitted, without trimming meaningful text.
            if ($value === '' || $value === null || ($name === 'resource' && $value === [])) {
                unset($parameters[$name]);
            }
        }

        return $parameters;
    }

    /**
     * Encode protocol parameters without PHP's bracket notation for repeated resources.
     *
     * @param array<array-key, list<string>|string> $parameters
     */
    public static function toQuery(array $parameters): string
    {
        $fields = [];

        foreach ($parameters as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $fields[] = urlencode((string) $name) . '=' . urlencode($item);
            }
        }

        return implode('&', $fields);
    }
}
