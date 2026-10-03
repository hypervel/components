<?php

declare(strict_types=1);

namespace Hypervel\Inertia\DevTools;

use Hypervel\Support\Arr;

trait RedactsSensitiveData
{
    protected const string REDACTED = '[REDACTED]';

    protected const string UNSERIALIZABLE = '[UNSERIALIZABLE]';

    /**
     * The headers whose value is a URL, so their sensitive query parameters are redacted.
     */
    protected const array URL_HEADERS = ['location', 'referer', 'x-inertia-location'];

    /**
     * Redact the values of the given keys throughout the data.
     *
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $keys
     * @return array<array-key, mixed>
     */
    protected function redact(array $data, array $keys): array
    {
        $lowered = $this->normalizeSensitiveKeys($keys);

        if ($lowered === []) {
            return $data;
        }

        return $this->redactRecursive($data, $lowered);
    }

    /**
     * Final storage pass for entry payloads. Earlier builders redact known request
     * surfaces, but this keeps persisted entries private if a future collector path
     * adds sensitive data before save.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function redactSensitiveStoragePayload(array $payload): array
    {
        $keys = config()->array('inertia.devtools.redact.keys', DevTools::DEFAULT_REDACT_KEYS);

        // Keys are redacted only within application values. A configured key such as id or name
        // can also name part of the entry's own structure: its metadata, route and source
        // details, the props map (each prop's metadata, keyed by prop path) and each captured
        // body's status. The metadata's URLs are request data, so the URL pass below still covers them.
        $payload = array_replace($payload, $this->redact(
            Arr::except($payload, ['__meta', 'props', 'route', 'renderSource', 'componentPath', 'http']),
            $keys,
        ));

        foreach (['requestBody', 'responseBody'] as $body) {
            if (is_array($payload['http'][$body]['value'] ?? null)) {
                $payload['http'][$body]['value'] = $this->redact($payload['http'][$body]['value'], $keys);
            }
        }

        foreach (['requestHeaders', 'responseHeaders'] as $bag) {
            if (is_array($payload['http'][$bag] ?? null)) {
                $payload['http'][$bag] = $this->redactHeaders($this->redact($payload['http'][$bag], $keys));
            }
        }

        return $this->sanitizeForJsonEncoding(array_replace($payload, $this->redactUrls(Arr::except($payload, 'props'), $keys)));
    }

    /**
     * Redact the values of the given lowercase keys at every depth.
     *
     * @param array<array-key, mixed> $data
     * @param array<int, string> $loweredKeys
     * @return array<array-key, mixed>
     */
    protected function redactRecursive(array $data, array $loweredKeys): array
    {
        return collect($data)
            ->map(function (mixed $value, int|string $key) use ($loweredKeys): mixed {
                if (is_string($key) && in_array(strtolower($key), $loweredKeys, true)) {
                    return self::REDACTED;
                }

                return is_array($value) ? $this->redactRecursive($value, $loweredKeys) : $value;
            })
            ->all();
    }

    /**
     * Flatten the given headers, redacting the sensitive ones and the sensitive query
     * parameters of URL headers.
     *
     * @param array<array-key, mixed> $headers
     * @return array<array-key, string>
     */
    protected function redactHeaders(array $headers): array
    {
        $sensitive = $this->normalizeSensitiveKeys(config()->array('inertia.devtools.redact.headers', DevTools::DEFAULT_REDACT_HEADERS));
        $keys = config()->array('inertia.devtools.redact.keys', DevTools::DEFAULT_REDACT_KEYS);

        return collect($headers)
            ->map(function (mixed $value, int|string $name) use ($sensitive, $keys): string {
                $header = strtolower((string) $name);

                if (in_array($header, $sensitive, true)) {
                    return self::REDACTED;
                }

                $value = is_array($value) ? implode(', ', $value) : (string) $value;

                return in_array($header, self::URL_HEADERS, true) ? $this->redactUrl($value, $keys) : $value;
            })
            ->all();
    }

    /**
     * Normalize the configured sensitive keys to unique lowercase strings.
     *
     * @param array<array-key, mixed> $keys
     * @return array<int, string>
     */
    protected function normalizeSensitiveKeys(array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $normalized[] = strtolower($key);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Redact sensitive query parameters from the URLs in the data.
     *
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $keys
     * @return array<array-key, mixed>
     */
    protected function redactUrls(array $data, array $keys): array
    {
        $lowered = $this->normalizeSensitiveKeys($keys);

        if ($lowered === []) {
            return $data;
        }

        return collect($data)
            ->map(function (mixed $value, int|string $key) use ($lowered): mixed {
                if (is_array($value)) {
                    return $this->redactUrls($value, $lowered);
                }

                if (is_string($value) && is_string($key) && in_array(strtolower($key), ['url', 'redirectlocation'], true)) {
                    // A URL is request data, so one stored under a configured key is redacted whole.
                    return in_array(strtolower($key), $lowered, true) ? self::REDACTED : $this->redactUrl($value, $lowered);
                }

                return $value;
            })
            ->all();
    }

    /**
     * Redact the values of sensitive query parameters. A parameter matches when its decoded
     * name or any of its bracketed segments (e.g. `filter[secret]`) is a sensitive key. The
     * query is not parsed and rebuilt, so every other byte of the URL is kept as recorded,
     * and relative or malformed URLs are redacted the same way.
     *
     * @param array<array-key, mixed> $keys
     */
    protected function redactUrl(string $url, array $keys): string
    {
        $lowered = $this->normalizeSensitiveKeys($keys);
        [$beforeFragment, $fragment] = array_pad(explode('#', $url, 2), 2, null);

        if ($lowered === [] || ! str_contains($beforeFragment, '?')) {
            return $url;
        }

        [$location, $query] = explode('?', $beforeFragment, 2);

        $parameters = array_map(function (string $parameter) use ($lowered): string {
            [$name, $value] = array_pad(explode('=', $parameter, 2), 2, null);
            $segments = preg_split('/[\[\]]+/', strtolower(urldecode($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return $value !== null && array_intersect($segments, $lowered) !== []
                ? $name . '=' . rawurlencode(self::REDACTED)
                : $parameter;
        }, explode('&', $query));

        return $location . '?' . implode('&', $parameters) . ($fragment === null ? '' : '#' . $fragment);
    }

    /**
     * Replace leaf values that cannot be stored as JSON with a marker.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    protected function sanitizeForJsonEncoding(array $data): array
    {
        return collect($data)
            ->map(function (mixed $value): mixed {
                if (is_array($value)) {
                    return $this->sanitizeForJsonEncoding($value);
                }

                return $this->isJsonEncodable($value) ? $value : self::UNSERIALIZABLE;
            })
            ->all();
    }

    /**
     * Determine if the value can be stored as JSON.
     */
    protected function isJsonEncodable(mixed $value): bool
    {
        if (is_object($value) || is_resource($value)) {
            return false;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== false;
    }
}
