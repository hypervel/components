<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache\Fixtures\Requests;

use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasJsonBody;

class AllowedCachedPostRequest extends Request implements Cacheable
{
    use HasCaching;
    use HasJsonBody;

    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::POST;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/data';
    }

    /**
     * Define the default body.
     *
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'name' => 'Sammy',
        ];
    }

    /**
     * Get the cache duration.
     */
    public function cacheFor(): int
    {
        return 60;
    }

    /**
     * Define the cacheable HTTP methods.
     *
     * @return list<Method>
     */
    protected function cacheableMethods(): array
    {
        return [Method::GET, Method::OPTIONS, Method::POST];
    }
}
