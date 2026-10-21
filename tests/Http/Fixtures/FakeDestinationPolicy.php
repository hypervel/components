<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Fixtures;

use Hypervel\Http\Client\Destinations\PublicDestinationPolicy;
use Hypervel\Http\Client\Destinations\ResolvedDestination;
use Psr\Http\Message\UriInterface;

class FakeDestinationPolicy extends PublicDestinationPolicy
{
    /**
     * The URLs passed to the policy, in order.
     *
     * @var list<string>
     */
    public array $urls = [];

    /**
     * The time budgets passed to the policy, in order.
     *
     * @var list<float>
     */
    public array $timeouts = [];

    /**
     * The hostnames the policy resolved, in order.
     *
     * @var list<string>
     */
    public array $resolvedHosts = [];

    /**
     * Create a new fake destination policy instance.
     *
     * @param array<string, list<string>> $addresses the DNS answers by hostname
     * @param list<string> $allowedNetworks
     * @param list<string> $allowedAddresses addresses allowed through the allowsAddress() hook
     */
    public function __construct(
        protected array $addresses = [],
        array $allowedNetworks = [],
        protected array $allowedAddresses = [],
        protected ?string $proxy = null,
        protected float $resolutionSeconds = 0.0,
    ) {
        parent::__construct($allowedNetworks);
    }

    /**
     * Resolve and authorize one outbound destination within the given time budget.
     */
    public function resolve(string $url, float $timeoutSeconds): ResolvedDestination
    {
        $this->urls[] = $url;
        $this->timeouts[] = $timeoutSeconds;

        return parent::resolve($url, $timeoutSeconds);
    }

    /**
     * Return an approved proxy for the destination.
     */
    protected function proxyFor(UriInterface $uri): ?string
    {
        return $this->proxy;
    }

    /**
     * Determine whether a non-public address may be used.
     */
    protected function allowsAddress(UriInterface $uri, string $address): bool
    {
        return in_array($address, $this->allowedAddresses, true);
    }

    /**
     * Resolve every address for a hostname from the configured answers.
     *
     * @return list<string>
     */
    protected function resolveHost(string $host, float $timeoutSeconds): array
    {
        $this->resolvedHosts[] = $host;

        if ($this->resolutionSeconds > 0.0) {
            usleep((int) ($this->resolutionSeconds * 1_000_000));
        }

        return $this->addresses[$host]
            ?? (filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : []);
    }
}
