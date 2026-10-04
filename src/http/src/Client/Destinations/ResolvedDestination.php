<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Destinations;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final readonly class ResolvedDestination
{
    private const int DEFAULT_HTTP_PORT = 80;

    private const int DEFAULT_HTTPS_PORT = 443;

    /** @var non-empty-list<string> */
    public array $addresses;

    /**
     * Create a new resolved destination instance.
     *
     * @param list<string> $fallbackAddresses
     */
    private function __construct(
        public UriInterface $uri,
        public string $resolvedHost,
        public int $resolvedPort,
        string $address,
        array $fallbackAddresses,
        public ?string $proxy,
        private bool $httpsProxy,
    ) {
        if ($resolvedHost === '' || $resolvedPort < 1 || $resolvedPort > 65535) {
            throw new InvalidArgumentException(
                'A resolved destination requires a valid host and port.',
            );
        }

        $addresses = [$address, ...$fallbackAddresses];

        foreach ($addresses as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException(
                    'A resolved destination requires valid IP addresses.',
                );
            }
        }

        $this->addresses = $addresses;
    }

    /**
     * Create a directly pinned destination.
     */
    public static function direct(
        UriInterface $uri,
        string $address,
        string ...$fallbackAddresses,
    ): self {
        self::validateTarget($uri);

        return new self(
            uri: $uri,
            resolvedHost: $uri->getHost(),
            resolvedPort: self::effectivePort($uri),
            address: $address,
            fallbackAddresses: $fallbackAddresses,
            proxy: null,
            httpsProxy: false,
        );
    }

    /**
     * Create a destination routed through a pinned proxy.
     *
     * A proxy without a port uses its scheme's default port (80 or 443), as any
     * HTTP URI does, rather than cURL's 1080: PSR-7 URIs drop an explicit
     * default port, so ":80" and no port cannot be told apart.
     */
    public static function proxy(
        UriInterface $uri,
        UriInterface $proxy,
        string $address,
        string ...$fallbackAddresses,
    ): self {
        self::validateTarget($uri);
        self::validateProxy($proxy);
        $port = self::effectivePort($proxy);

        return new self(
            uri: $uri,
            resolvedHost: $proxy->getHost(),
            resolvedPort: $port,
            address: $address,
            fallbackAddresses: $fallbackAddresses,
            proxy: "{$proxy->getScheme()}://{$proxy->getHost()}:{$port}",
            httpsProxy: $proxy->getScheme() === 'https',
        );
    }

    /**
     * Determine whether the route uses a TLS-protected proxy connection.
     */
    public function usesHttpsProxy(): bool
    {
        return $this->httpsProxy;
    }

    /**
     * Return the exact cURL connection-pinning options.
     *
     * Direct substitutions must not enter the handler's shared DNS cache.
     * Proxy substitutions retain the hostname required for proxy TLS.
     *
     * @return array<int, list<string>>
     */
    public function curlOptions(): array
    {
        $addresses = array_map(
            static fn (string $address): string => str_contains($address, ':')
                ? "[{$address}]"
                : $address,
            $this->addresses,
        );

        if ($this->proxy === null) {
            $connectionHost = $this->connectionHost();

            // The synthetic host lets cURL fail over across the complete vetted
            // set without adding the real target to the handler's shared DNS cache.
            return [
                CURLOPT_RESOLVE => [implode(':', [
                    "+{$connectionHost}",
                    $this->resolvedPort,
                    implode(',', $addresses),
                ])],
                CURLOPT_CONNECT_TO => [implode(':', [
                    $this->resolvedHost,
                    $this->resolvedPort,
                    $connectionHost,
                    $this->resolvedPort,
                ])],
            ];
        }

        return [CURLOPT_RESOLVE => [implode(':', [
            $this->resolvedHost,
            $this->resolvedPort,
            implode(',', $addresses),
        ])]];
    }

    /**
     * Return an isolated DNS name for one direct authorized route.
     */
    private function connectionHost(): string
    {
        $digest = hash('sha256', implode("\0", [
            $this->resolvedHost,
            (string) $this->resolvedPort,
            ...$this->addresses,
        ]));

        return substr($digest, 0, 32)
            . '.'
            . substr($digest, 32)
            . '.hypervel-http.invalid';
    }

    /**
     * Validate the structural target contract independently of its policy.
     */
    private static function validateTarget(UriInterface $uri): void
    {
        if (! in_array($uri->getScheme(), ['http', 'https'], true)
            || $uri->getHost() === ''
            || $uri->getUserInfo() !== ''
            || $uri->getFragment() !== '') {
            throw new InvalidArgumentException(
                'A resolved destination requires an HTTP or HTTPS host without user information or a fragment.',
            );
        }
    }

    /**
     * Validate the structural proxy contract independently of its policy.
     */
    private static function validateProxy(UriInterface $uri): void
    {
        if (! in_array($uri->getScheme(), ['http', 'https'], true)
            || $uri->getHost() === ''
            || $uri->getUserInfo() !== ''
            || $uri->getPath() !== ''
            || $uri->getQuery() !== ''
            || $uri->getFragment() !== '') {
            throw new InvalidArgumentException(
                'A resolved proxy requires an HTTP or HTTPS authority without user information, a path, query, or fragment.',
            );
        }
    }

    /**
     * Return the URI's effective network port.
     */
    private static function effectivePort(UriInterface $uri): int
    {
        return $uri->getPort() ?? ($uri->getScheme() === 'https'
            ? self::DEFAULT_HTTPS_PORT
            : self::DEFAULT_HTTP_PORT);
    }
}
