<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination\Contracts;

use Hypervel\Saloon\Http\Response;

/** @template TItem */
interface MapPaginatedResponseItems
{
    /**
     * Map the items from a paginated response.
     *
     * @param Response<mixed> $response
     * @return array<array-key, TItem>
     */
    public function mapPaginatedResponseItems(Response $response): array;
}
