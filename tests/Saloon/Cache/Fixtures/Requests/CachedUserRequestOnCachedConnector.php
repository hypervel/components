<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache\Fixtures\Requests;

use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

class CachedUserRequestOnCachedConnector extends Request implements Cacheable
{
    use HasCaching;

    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /**
     * Get the cache duration.
     */
    public function cacheFor(): int
    {
        return 30;
    }

    /**
     * Get the cache store name.
     */
    public function cacheStore(): string
    {
        return 'custom';
    }
}
