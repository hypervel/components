<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use Psr\Http\Message\UriInterface;

class HttpTelemetryAttributes
{
    /** @var array<string, true> */
    protected array $sensitiveQueryParameters;

    /** @var array<string, true> */
    protected array $sensitiveHeaders;

    /** @var array<string, true> */
    protected array $requestHeaders;

    /** @var array<string, true> */
    protected array $responseHeaders;

    /**
     * Create shared HTTP attribute normalization.
     *
     * @param list<string> $sensitiveQueryParameters
     * @param list<string> $sensitiveHeaders
     * @param list<string> $requestHeaders
     * @param list<string> $responseHeaders
     */
    public function __construct(
        protected bool $recordQuery,
        array $sensitiveQueryParameters,
        array $sensitiveHeaders,
        array $requestHeaders,
        array $responseHeaders,
    ) {
        $this->sensitiveQueryParameters = $this->set(array_merge([
            'access_token',
            'api_key',
            'apikey',
            'awsaccesskeyid',
            'password',
            'passwd',
            'secret',
            'sig',
            'signature',
            'token',
            'x-amz-credential',
            'x-amz-signature',
            'x-goog-signature',
        ], $sensitiveQueryParameters));
        $this->sensitiveHeaders = $this->set(array_merge([
            'authorization',
            'proxy-authorization',
            'php-auth-pw',
            'cookie',
            'set-cookie',
        ], $sensitiveHeaders));
        $this->requestHeaders = $this->set($requestHeaders);
        $this->responseHeaders = $this->set($responseHeaders);
    }

    /**
     * Return configured request-header attributes.
     *
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    public function requestHeaderAttributes(array $headers): array
    {
        return $this->headerAttributes($headers, $this->requestHeaders, HttpAttributes::HTTP_REQUEST_HEADER);
    }

    /**
     * Return configured response-header attributes.
     *
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    public function responseHeaderAttributes(array $headers): array
    {
        return $this->headerAttributes($headers, $this->responseHeaders, HttpAttributes::HTTP_RESPONSE_HEADER);
    }

    /**
     * Return a redacted query string when query capture is enabled.
     */
    public function query(?string $query): ?string
    {
        if (! $this->recordQuery || $query === null || $query === '') {
            return null;
        }

        $parts = preg_split('/([&;])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return null;
        }

        foreach ($parts as $index => $part) {
            if ($part === '&' || $part === ';' || $part === '') {
                continue;
            }

            [$name] = explode('=', $part, 2);
            $decodedName = strtolower(rawurldecode(str_replace('+', ' ', $name)));

            if (isset($this->sensitiveQueryParameters[$decodedName])) {
                $parts[$index] = $name . '=REDACTED';
            }
        }

        return implode('', $parts);
    }

    /**
     * Return the version component from a server protocol token.
     */
    public function protocolVersion(?string $protocolVersion): ?string
    {
        if ($protocolVersion === null) {
            return null;
        }

        return str_starts_with($protocolVersion, 'HTTP/')
            ? substr($protocolVersion, 5)
            : $protocolVersion;
    }

    /**
     * Return a valid non-negative content length.
     */
    public function contentLength(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return $length === false ? null : $length;
    }

    /**
     * Return a client URL with configured query detail and redacted credentials.
     */
    public function fullUrl(UriInterface $uri): string
    {
        $userInfo = $uri->getUserInfo();

        if ($userInfo !== '') {
            $uri = str_contains($userInfo, ':')
                ? $uri->withUserInfo('REDACTED', 'REDACTED')
                : $uri->withUserInfo('REDACTED');
        }

        return (string) $uri->withQuery($this->query($uri->getQuery()) ?? '');
    }

    /**
     * Return a low-cardinality HTTP span name.
     */
    public function spanName(string $method, ?string $target = null): string
    {
        $method = $method === HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER ? 'HTTP' : $method;

        return $target === null ? $method : "{$method} {$target}";
    }

    /**
     * Return allowlisted header attributes.
     *
     * @param array<string, list<string>> $headers
     * @param array<string, true> $allowlist
     * @return array<string, list<string>>
     */
    protected function headerAttributes(array $headers, array $allowlist, string $prefix): array
    {
        if ($allowlist === []) {
            return [];
        }

        $attributes = [];

        foreach ($headers as $name => $values) {
            $normalizedName = strtolower($name);

            if (! isset($allowlist[$normalizedName])) {
                continue;
            }

            $attributes["{$prefix}.{$normalizedName}"] = isset($this->sensitiveHeaders[$normalizedName])
                ? ['REDACTED']
                : $values;
        }

        return $attributes;
    }

    /**
     * Convert a string list into a lowercase lookup set.
     *
     * @param list<string> $values
     * @return array<string, true>
     */
    protected function set(array $values): array
    {
        $set = [];

        foreach ($values as $value) {
            $set[strtolower($value)] = true;
        }

        return $set;
    }
}
