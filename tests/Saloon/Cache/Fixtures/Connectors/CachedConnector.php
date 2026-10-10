<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache\Fixtures\Connectors;

use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

// Connectors are read-only, so they implement Cacheable without the HasCaching request controls.
class CachedConnector extends Connector implements Cacheable
{
    /**
     * Define the base url of the api.
     */
    public function resolveBaseUrl(): string
    {
        return TestConnector::API_URL;
    }

    /**
     * Get the cache duration.
     */
    public function cacheFor(): int
    {
        return 60;
    }

    /**
     * Get the cache store name.
     */
    public function cacheStore(): ?string
    {
        return null;
    }
}
