<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Destinations;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Swoole\Coroutine\System;
use Symfony\Component\HttpFoundation\IpUtils;

class PublicDestinationPolicy implements DestinationPolicy
{
    /** @var list<string> */
    protected const array DISALLOWED_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        // IPv6 outside the 2000::/3 global unicast block.
        '::/3',
        '4000::/2',
        '8000::/1',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3ffe::/16',
        '3fff::/20',
    ];

    /**
     * Create a new public destination policy instance.
     *
     * @param list<string> $allowedNetworks addresses or CIDR ranges that are allowed even when they are not public
     */
    public function __construct(
        protected array $allowedNetworks = [],
    ) {
    }

    /**
     * Resolve and authorize one outbound destination within the given time budget.
     *
     * @throws DisallowedDestinationException
     * @throws DestinationResolutionException
     * @throws ProxyConnectionException
     */
    public function resolve(string $url, float $timeoutSeconds): ResolvedDestination
    {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException(
                'The destination resolution timeout must be positive and finite.',
            );
        }

        $uri = $this->normalizeUri($url);
        $proxy = $this->proxyFor($uri);

        if ($proxy === null) {
            return ResolvedDestination::direct(
                $uri,
                ...$this->resolveAuthorizedAddresses($uri, $timeoutSeconds),
            );
        }

        // The proxy resolves hostname targets itself, but an address literal can still be checked here.
        $target = $this->addressHost($uri->getHost());

        if (filter_var($target, FILTER_VALIDATE_IP) !== false) {
            $this->authorizeAddress($uri, $target);
        }

        $proxyUri = $this->normalizeProxy($proxy);

        try {
            $addresses = $this->resolveAuthorizedAddresses($proxyUri, $timeoutSeconds);
        } catch (DestinationResolutionException $exception) {
            throw new ProxyConnectionException(
                "The proxy [{$proxyUri->getHost()}] could not be resolved.",
                previous: $exception,
            );
        }

        return ResolvedDestination::proxy($uri, $proxyUri, ...$addresses);
    }

    /**
     * Validate a destination URL without resolving it, such as before storing one a user entered.
     *
     * A host given as an address literal is authorized now. A hostname is not
     * resolved, so its addresses are checked when a request resolves them.
     *
     * @throws DisallowedDestinationException
     */
    public function validate(string $url): void
    {
        $uri = $this->normalizeUri($url);
        $address = $this->addressHost($uri->getHost());

        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            $this->authorizeAddress($uri, $address);
        }
    }

    /**
     * Return an approved proxy for the destination.
     *
     * Applications that require trusted egress proxies may extend this policy
     * and select an absolute URL from a bounded operator-configured set. The
     * policy then vets and pins only the proxy: hostname targets are resolved
     * by the proxy, so it must enforce the same destination rules itself.
     */
    protected function proxyFor(UriInterface $uri): ?string
    {
        return null;
    }

    /**
     * Determine whether a non-public address may be used.
     *
     * Applications may narrowly allow known internal destinations by
     * extending this policy. Every resolved address must be allowed.
     */
    protected function allowsAddress(UriInterface $uri, string $address): bool
    {
        return false;
    }

    /**
     * Resolve every address for a hostname.
     *
     * @return list<string>
     *
     * @throws DestinationResolutionException
     */
    protected function resolveHost(string $host, float $timeoutSeconds): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $startedAt = hrtime(true);
        $familyTimeoutSeconds = $timeoutSeconds / 2;
        $addresses = [];

        foreach ([AF_INET, AF_INET6] as $family) {
            $remaining = min(
                $familyTimeoutSeconds,
                $timeoutSeconds - ((hrtime(true) - $startedAt) / 1_000_000_000),
            );

            if ($remaining <= 0.0) {
                throw new DestinationResolutionException(
                    "The host [{$host}] could not be resolved within the request timeout.",
                );
            }

            $resolved = $this->resolveAddressFamily($host, $family, $remaining);

            if (is_array($resolved)) {
                $addresses = [...$addresses, ...$resolved];
            }
        }

        return $addresses;
    }

    /**
     * Resolve one address family within its share of the request budget.
     *
     * @return false|list<string>
     */
    protected function resolveAddressFamily(
        string $host,
        int $family,
        float $timeoutSeconds,
    ): array|false {
        // Swoole declares this parameter nullable but its implementation rejects null,
        // so pass an empty string instead of relying on the declared default.
        return System::getaddrinfo(
            $host,
            $family,
            service: '',
            timeout: $timeoutSeconds,
        );
    }

    /**
     * Normalize an outbound destination URI.
     *
     * @throws DisallowedDestinationException
     */
    private function normalizeUri(string $url): UriInterface
    {
        if ($url === '' || preg_match('/[^\x20-\x7e]/', $url) === 1) {
            throw new DisallowedDestinationException(
                'Destination URLs must be non-empty printable ASCII strings.',
            );
        }

        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException $exception) {
            throw new DisallowedDestinationException(
                'The destination URL is invalid.',
                previous: $exception,
            );
        }

        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());

        if (! str_starts_with($host, '[')) {
            $host = rtrim($host, '.');
        }

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || $uri->getUserInfo() !== '') {
            throw new DisallowedDestinationException(
                'Destination URLs require an HTTP or HTTPS host without user information.',
            );
        }

        $this->validateHost($host);

        // Fragments are never sent, so they cannot affect where the request goes.
        return $uri
            ->withScheme($scheme)
            ->withHost($host)
            ->withFragment('');
    }

    /**
     * Normalize an approved proxy URI.
     *
     * @throws DisallowedDestinationException
     */
    private function normalizeProxy(string $proxy): UriInterface
    {
        $uri = $this->normalizeUri($proxy);

        if (! in_array($uri->getPath(), ['', '/'], true) || $uri->getQuery() !== '') {
            throw new DisallowedDestinationException(
                'Proxy URLs cannot contain a path or query string.',
            );
        }

        return $uri->withPath('');
    }

    /**
     * Resolve every address after authorizing the complete DNS result.
     *
     * @return non-empty-list<string>
     *
     * @throws DisallowedDestinationException
     * @throws DestinationResolutionException
     */
    private function resolveAuthorizedAddresses(
        UriInterface $uri,
        float $timeoutSeconds,
    ): array {
        $addresses = array_values(array_unique(
            $this->resolveHost($this->addressHost($uri->getHost()), $timeoutSeconds),
        ));
        sort($addresses, SORT_STRING);

        if ($addresses === []) {
            throw new DestinationResolutionException(
                "The host [{$uri->getHost()}] did not resolve to an address.",
            );
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new DestinationResolutionException(
                    "The host [{$uri->getHost()}] resolved to an invalid address.",
                );
            }

            $this->authorizeAddress($uri, $address);
        }

        return $addresses;
    }

    /**
     * Ensure an address is public or explicitly allowed.
     *
     * @throws DisallowedDestinationException
     */
    private function authorizeAddress(UriInterface $uri, string $address): void
    {
        // DNS64 uses this prefix to reach IPv4 services; authorize the translated
        // destination while retaining the original address for routing and overrides.
        $destination = IpUtils::checkIp($address, '64:ff9b::/96')
            ? inet_ntop(substr(inet_pton($address), 12))
            : $address;

        if (IpUtils::checkIp($destination, self::DISALLOWED_NETWORKS)
            && ! IpUtils::checkIp($address, $this->allowedNetworks)
            && ! $this->allowsAddress($uri, $address)) {
            throw new DisallowedDestinationException(
                "The host [{$uri->getHost()}] resolves to a disallowed address [{$address}].",
            );
        }
    }

    /**
     * Validate an ASCII IP literal or DNS hostname.
     *
     * @throws DisallowedDestinationException
     */
    private function validateHost(string $host): void
    {
        if (filter_var($this->addressHost($host), FILTER_VALIDATE_IP) !== false) {
            return;
        }

        if (strlen($host) > 253
            || preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)(?:\.(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?))*\z/',
                $host,
            ) !== 1) {
            throw new DisallowedDestinationException(
                "The destination URL contains an invalid host [{$host}].",
            );
        }
    }

    /**
     * Return an address literal without URI brackets.
     */
    private function addressHost(string $host): string
    {
        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }
}
