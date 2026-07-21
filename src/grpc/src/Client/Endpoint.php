<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class Endpoint
{
    private function __construct(
        public string $host,
        public int $port,
        public bool $tls,
        public string $authority,
        public string $peer,
    ) {
    }

    /**
     * Parse and normalize a direct gRPC endpoint.
     */
    public static function parse(string $target, ?bool $tls = null): self
    {
        if ($target === '') {
            throw new InvalidArgumentException('The gRPC target cannot be empty.');
        }

        $scheme = null;
        $parseTarget = '//' . $target;

        if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $target, $matches) === 1) {
            $scheme = strtolower($matches[1]);

            if ($scheme !== 'http' && $scheme !== 'https') {
                throw new InvalidArgumentException("The gRPC target scheme [{$scheme}] is not supported.");
            }

            $parseTarget = $target;
        } elseif (str_contains($target, '://')) {
            throw new InvalidArgumentException('The gRPC target scheme is malformed.');
        }

        $parts = parse_url($parseTarget);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('The gRPC target is malformed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('A gRPC target cannot contain user information.');
        }

        if (array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException('A gRPC target cannot contain a query string or fragment.');
        }

        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            throw new InvalidArgumentException('A gRPC target cannot contain a path.');
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('The gRPC target host is missing.');
        }

        $authorityStart = $scheme === null ? 0 : strlen($scheme) + 3;
        $authorityLength = strcspn($target, '/?#', $authorityStart);
        $rawAuthority = substr($target, $authorityStart, $authorityLength);

        if (str_ends_with($rawAuthority, ':')) {
            throw new InvalidArgumentException('The gRPC target port is missing.');
        }

        $bracketed = str_starts_with($host, '[') && str_ends_with($host, ']');

        if ($bracketed) {
            $host = substr($host, 1, -1);

            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('The gRPC target contains an invalid IPv6 address.');
            }

            $authorityHost = '[' . strtolower($host) . ']';
        } else {
            if (str_contains($host, ':')) {
                throw new InvalidArgumentException('IPv6 gRPC targets must use bracket notation.');
            }

            $host = strtolower($host);

            if (
                filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            ) {
                throw new InvalidArgumentException('The gRPC target host is invalid.');
            }

            $authorityHost = $host;
        }

        $inferredTls = $scheme === 'https';

        if (($scheme === 'http' && $tls === true) || ($scheme === 'https' && $tls === false)) {
            throw new InvalidArgumentException('The gRPC TLS option conflicts with the target scheme.');
        }

        $tls ??= $inferredTls;
        $port = $parts['port'] ?? ($tls ? 443 : 80);

        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The gRPC target port must be between 1 and 65535.');
        }

        $authority = "{$authorityHost}:{$port}";

        return new self(strtolower($host), $port, $tls, $authority, $authority);
    }
}
