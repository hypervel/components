<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Pagination\Contracts\MapPaginatedResponseItems;
use Hypervel\Saloon\Pagination\Contracts\Paginatable;

class MappedPagedRequest extends Request implements Paginatable, MapPaginatedResponseItems
{
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/superheroes/per-page';
    }

    /**
     * Map the items from the paginator.
     */
    public function mapPaginatedResponseItems(Response $response): array
    {
        return $response->collect('data')->pluck('superhero')->toArray();
    }
}
