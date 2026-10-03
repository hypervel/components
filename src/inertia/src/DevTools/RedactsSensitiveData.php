<?php

declare(strict_types=1);

namespace Hypervel\Inertia\DevTools;

use Hypervel\Support\Uri;
use Throwable;

trait RedactsSensitiveData
{
    protected const string REDACTED = '[REDACTED]';

    protected const string UNSERIALIZABLE = '[UNSERIALIZABLE]';

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

        $payload = $this->redact($payload, $keys);
        $payload = $this->redactUrls($payload, $keys);
        $payload = $this->redactHeaderBags($payload);

        return $this->sanitizeForJsonEncoding($payload);
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
     * Flatten the given headers, redacting the sensitive ones.
     *
     * @param array<array-key, mixed> $headers
     * @return array<array-key, string>
     */
    protected function redactHeaders(array $headers): array
    {
        $sensitive = $this->normalizeSensitiveKeys(config()->array('inertia.devtools.redact.headers', DevTools::DEFAULT_REDACT_HEADERS));

        return collect($headers)
            ->map(function (mixed $value, int|string $name) use ($sensitive): string {
                if (is_string($name) && in_array(strtolower($name), $sensitive, true)) {
                    return self::REDACTED;
                }

                return is_array($value) ? implode(', ', $value) : (string) $value;
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
                    return $this->redactUrl($value, $lowered);
                }

                return $value;
            })
            ->all();
    }

    /**
     * Redact sensitive query parameters, preserving scheme, host, path and fragment. The URL
     * may be relative or malformed, so an unparseable value is returned unchanged rather
     * than throwing: redaction must never break the recorder.
     *
     * @param array<int, string> $keys
     */
    protected function redactUrl(string $url, array $keys): string
    {
        $lowered = $this->normalizeSensitiveKeys($keys);

        if ($lowered === [] || ! str_contains($url, '?')) {
            return $url;
        }

        try {
            $uri = Uri::of($url);
            $params = $uri->query()->all();

            if ($params === []) {
                return $url;
            }

            return $uri->withQuery($this->redactQueryParameters($params, $lowered), merge: false)->value();
        } catch (Throwable) {
            return $url;
        }
    }

    /**
     * Walk the parsed query parameters and replace the value of any sensitive key with the
     * redacted marker. A sensitive key redacts its whole subtree; nested keys (e.g. from
     * `filter[secret]`) are matched at their own depth.
     *
     * @param array<array-key, mixed> $params
     * @param array<int, string> $loweredKeys
     * @return array<array-key, mixed>
     */
    protected function redactQueryParameters(array $params, array $loweredKeys): array
    {
        $result = [];

        foreach ($params as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $loweredKeys, true)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = is_array($value) ? $this->redactQueryParameters($value, $loweredKeys) : $value;
        }

        return $result;
    }

    /**
     * Redact the sensitive headers in every header bag in the data.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    protected function redactHeaderBags(array $data): array
    {
        return collect($data)
            ->map(function (mixed $value, int|string $key): mixed {
                if (! is_array($value)) {
                    return $value;
                }

                if (is_string($key) && in_array(strtolower($key), ['requestheaders', 'responseheaders'], true)) {
                    return $this->redactHeaders($value);
                }

                return $this->redactHeaderBags($value);
            })
            ->all();
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
