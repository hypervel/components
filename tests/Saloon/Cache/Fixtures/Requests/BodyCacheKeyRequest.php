<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache\Fixtures\Requests;

use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasJsonBody;

class BodyCacheKeyRequest extends Request implements Cacheable
{
    use HasCaching;
    use HasJsonBody;

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
        return 60;
    }

    /**
     * Define a custom cache key.
     */
    protected function cacheKey(PendingRequest $pendingRequest): ?string
    {
        return json_encode($pendingRequest->body(), JSON_THROW_ON_ERROR);
    }
}
