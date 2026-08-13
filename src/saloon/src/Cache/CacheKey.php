<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Cache;

use Hypervel\Saloon\Cache\Exceptions\CachingException;
use Hypervel\Saloon\Exceptions\BodyException;
use Hypervel\Saloon\Http\HeaderNormalizer;
use Hypervel\Saloon\Http\PendingRequest;

final class CacheKey
{
    /**
     * Options that can change a successful response.
     *
     * @var list<string>
     */
    private const array RESPONSE_OPTIONS = [
        'allow_redirects',
        'cert',
        'crypto_method',
        'curl',
        'decode_content',
        'expect',
        'force_ip_resolve',
        'idn_conversion',
        'proxy',
        'ssl_key',
        'verify',
        'version',
    ];

    /**
     * Create the bounded cache key for a pending request.
     *
     * @param array<string, mixed> $transportOptions
     */
    public function make(
        PendingRequest $pendingRequest,
        array $transportOptions,
        ?string $customKey = null,
        ?string $scope = null,
    ): string {
        if (array_key_exists('sink', $transportOptions)) {
            throw new CachingException('Cached Saloon requests cannot use the [sink] option. Export the returned response explicitly instead.');
        }

        $identity = $customKey === null
            ? $this->requestIdentity($pendingRequest, $transportOptions)
            : ['custom' => $customKey];

        return 'saloon:' . hash('sha256', $this->encode([$identity, 'scope' => $scope]));
    }

    /**
     * Build the canonical request identity.
     *
     * @param array<string, mixed> $transportOptions
     * @return array<string, mixed>
     */
    private function requestIdentity(PendingRequest $pendingRequest, array $transportOptions): array
    {
        return [
            'connector' => $pendingRequest->connector()::class,
            'request' => $pendingRequest->request()::class,
            'method' => $pendingRequest->method()->value,
            'uri' => (string) $pendingRequest->uri(),
            'headers' => $this->headers($pendingRequest->headers()),
            'cookies' => $pendingRequest->cookies(),
            'authentication' => $pendingRequest->transportAuthentication(),
            'certificate' => $pendingRequest->certificate(),
            'body' => $this->body($pendingRequest),
            'options' => array_intersect_key($transportOptions, array_flip(self::RESPONSE_OPTIONS)),
        ];
    }

    /**
     * Normalize headers without changing value order.
     *
     * @param array<string, mixed> $headers
     * @return list<array{string, list<string>|string}>
     */
    private function headers(array $headers): array
    {
        $normalized = [];

        foreach (HeaderNormalizer::normalize($headers) as $name => $values) {
            $normalized[] = [strtolower($name), $values];
        }

        usort($normalized, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $normalized;
    }

    /**
     * Read the prepared body without changing its position.
     */
    private function body(PendingRequest $pendingRequest): ?string
    {
        $body = $pendingRequest->preparedBody();

        if ($body === null) {
            return null;
        }

        if (! $body->isSeekable()) {
            throw new BodyException('A cacheable request with a non-seekable body must define a custom cache key.');
        }

        $position = $body->tell();

        try {
            return $body->getContents();
        } finally {
            $body->seek($position);
        }
    }

    /**
     * Encode a supported value into a canonical representation.
     */
    private function encode(mixed $value): string
    {
        if ($value === null) {
            return 'n;';
        }

        if (is_bool($value)) {
            return $value ? 'b:1;' : 'b:0;';
        }

        if (is_int($value)) {
            return 'i:' . $value . ';';
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new CachingException('Cache identity values must contain only finite numbers.');
            }

            return 'f:' . serialize($value) . ';';
        }

        if (is_string($value)) {
            return 's:' . strlen($value) . ':' . $value . ';';
        }

        if (! is_array($value)) {
            throw new CachingException('Cache identity values must contain only arrays, strings, numbers, booleans, or null.');
        }

        if (array_is_list($value)) {
            $encoded = 'l:' . count($value) . ':';

            foreach ($value as $item) {
                $encoded .= $this->encode($item);
            }

            return $encoded;
        }

        $entries = [];

        foreach ($value as $key => $item) {
            $encodedKey = $this->encode($key);
            $entries[$encodedKey] = $this->encode($item);
        }

        ksort($entries, SORT_STRING);
        $encoded = 'm:' . count($entries) . ':';

        foreach ($entries as $key => $item) {
            $encoded .= $key . $item;
        }

        return $encoded;
    }
}
