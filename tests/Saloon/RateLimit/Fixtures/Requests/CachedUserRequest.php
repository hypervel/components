<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests;

use DateInterval;
use DateTimeInterface;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

// Stands in for upstream's cache middleware: a cache hit returns before rate limiting is applied.
final class CachedUserRequest extends Request implements Cacheable
{
    use HasCaching;

    /**
     * Define the HTTP method.
     */
    protected Method $method = Method::GET;

    /**
     * Get the cache duration.
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 60;
    }

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }
}
