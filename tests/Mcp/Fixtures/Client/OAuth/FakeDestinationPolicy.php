<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures\Client\OAuth;

use Hypervel\Mcp\Client\OAuth\OAuthDestinationPolicy;

class FakeDestinationPolicy extends OAuthDestinationPolicy
{
    /**
     * Supply controlled DNS answers while retaining destination validation.
     *
     * @param array<string, list<string>> $addresses
     * @param list<string> $allowedNetworks
     */
    public function __construct(
        private array $addresses = [],
        array $allowedNetworks = [],
    ) {
        parent::__construct($allowedNetworks);
    }

    /**
     * Resolve fake hostnames without contacting DNS.
     *
     * @return list<string>
     */
    protected function resolveHost(string $host, float $timeoutSeconds): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->addresses[$host] ?? ['93.184.216.34'];
    }
}
