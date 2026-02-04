<?php

declare(strict_types=1);

namespace Hypervel\Scout\Contracts;

use Hypervel\Contracts\Pagination\LengthAwarePaginator;
use Hypervel\Contracts\Pagination\Paginator;
use Hypervel\Scout\Builder;

/**
 * Contract for engines that handle pagination directly.
 *
 * Engines implementing this contract return paginators directly from search
 * results, bypassing the default Builder pagination logic. This is useful
 * for engines that can calculate total counts efficiently during search.
 */
interface PaginatesEloquentModels
{
    /**
     * Paginate the given search on the engine.
     */
    public function paginate(Builder $builder, int $perPage, int $page): LengthAwarePaginator;

    /**
     * Paginate the given search on the engine using simple pagination.
     */
    public function simplePaginate(Builder $builder, int $perPage, int $page): Paginator;
}
