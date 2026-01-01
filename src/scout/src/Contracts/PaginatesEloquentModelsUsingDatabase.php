<?php

declare(strict_types=1);

namespace Hypervel\Scout\Contracts;

use Hyperf\Contract\LengthAwarePaginatorInterface;
use Hyperf\Contract\PaginatorInterface;
use Hypervel\Scout\Builder;

/**
 * Contract for engines that paginate directly from the database.
 *
 * This interface is implemented by the DatabaseEngine to provide native
 * database pagination, which is more efficient than the default Scout
 * pagination that fetches IDs first and then hydrates models.
 */
interface PaginatesEloquentModelsUsingDatabase
{
    /**
     * Paginate the given search on the engine using database pagination.
     */
    public function paginateUsingDatabase(
        Builder $builder,
        int $perPage,
        string $pageName,
        int $page
    ): LengthAwarePaginatorInterface;

    /**
     * Paginate the given search on the engine using simple database pagination.
     */
    public function simplePaginateUsingDatabase(
        Builder $builder,
        int $perPage,
        string $pageName,
        int $page
    ): PaginatorInterface;
}
