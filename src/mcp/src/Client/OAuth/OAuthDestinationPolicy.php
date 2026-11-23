<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

use Hypervel\Http\Client\Destinations\PublicDestinationPolicy;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\IpUtils;

class OAuthDestinationPolicy extends PublicDestinationPolicy
{
    public const array LOCALHOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * Allow local OAuth development without trusting arbitrary private DNS targets.
     */
    protected function allowsAddress(UriInterface $uri, string $address): bool
    {
        return in_array(trim($uri->getHost(), '[]'), self::LOCALHOSTS, true)
            && IpUtils::checkIp($address, ['127.0.0.0/8', '::1']);
    }

    /**
     * Resolve hostnames through AuthServerDiscovery::resolveHostsUsing() when a callback is set.
     *
     * @return list<string>
     */
    protected function resolveHost(string $host, float $timeoutSeconds): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false && ($addresses = AuthServerDiscovery::resolvedHostAddresses($host)) !== null) {
            return $addresses;
        }

        return parent::resolveHost($host, $timeoutSeconds);
    }
}
