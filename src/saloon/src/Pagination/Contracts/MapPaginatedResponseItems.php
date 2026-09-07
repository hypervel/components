<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination\Contracts;

use Hypervel\Saloon\Http\Response;

interface MapPaginatedResponseItems
{
    /**
     * Map the items from a paginated response.
     *
     * @return array<mixed, mixed>
     */
    public function mapPaginatedResponseItems(Response $response): array;
}
