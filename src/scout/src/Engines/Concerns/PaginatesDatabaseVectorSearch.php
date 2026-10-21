<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines\Concerns;

use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Exceptions\ScoutException;

trait PaginatesDatabaseVectorSearch
{
    /**
     * Paginate a hybrid search using a bounded fused result window.
     */
    protected function paginateHybridSearch(Builder $builder, ?int $perPage, string $pageName, ?int $page): LengthAwarePaginator
    {
        [$page, $perPage, $maximum] = $this->hybridPaginationWindow(
            $builder,
            $perPage,
            $pageName,
            $page
        );

        $models = $this->hybridSearchModels($builder, $maximum);

        return new LengthAwarePaginator(
            $models->slice(($page - 1) * $perPage, $perPage)->values(),
            $models->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        );
    }

    /**
     * Simply paginate a hybrid search using a bounded fused result window.
     */
    protected function simplePaginateHybridSearch(Builder $builder, ?int $perPage, string $pageName, ?int $page): Paginator
    {
        [$page, $perPage, $maximum] = $this->hybridPaginationWindow(
            $builder,
            $perPage,
            $pageName,
            $page
        );

        $models = $this->hybridSearchModels($builder, $maximum);

        $paginator = new Paginator(
            $models->slice(($page - 1) * $perPage, $perPage)->values(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        );

        return $paginator->hasMorePagesWhen(($page * $perPage) < $models->count());
    }

    /**
     * Resolve and validate a hybrid pagination window.
     *
     * @return array{int, int, int}
     */
    protected function hybridPaginationWindow(Builder $builder, ?int $perPage, string $pageName, ?int $page): array
    {
        [$page, $perPage, $offset] = $this->resolvePaginationState(
            $builder,
            $perPage,
            $pageName,
            $page
        );

        if ($offset + $perPage > 1000) {
            throw new ScoutException('Database hybrid search results may not be paginated beyond 1,000 records.');
        }

        return [$page, $perPage, min($builder->limit ?? 1000, 1000)];
    }

    /**
     * Paginate a semantic search while respecting Scout's result limit.
     */
    protected function paginateSemanticSearch(Builder $builder, ?int $perPage, string $pageName, ?int $page): LengthAwarePaginator
    {
        [$page, $perPage, $offset] = $this->resolvePaginationState(
            $builder,
            $perPage,
            $pageName,
            $page
        );

        $maximum = $this->resolveResultLimit($builder);

        $query = $this->buildSemanticSearchQuery($builder);

        $total = min(
            (int) (clone $query)->toBase()->getCountForPagination(),
            $maximum
        );

        $models = $offset >= $maximum
            ? $builder->model->newCollection()
            : $query->offset($offset)->limit(min($perPage, $maximum - $offset))->get();

        return new LengthAwarePaginator(
            $models,
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        );
    }

    /**
     * Simply paginate a semantic search while respecting Scout's result limit.
     */
    protected function simplePaginateSemanticSearch(Builder $builder, ?int $perPage, string $pageName, ?int $page): Paginator
    {
        [$page, $perPage, $offset] = $this->resolvePaginationState(
            $builder,
            $perPage,
            $pageName,
            $page
        );

        $limit = min(
            $perPage + 1,
            max(0, $this->resolveResultLimit($builder) - $offset)
        );

        $models = $limit === 0
            ? $builder->model->newCollection()
            : $this->buildSemanticSearchQuery($builder)->offset($offset)->limit($limit)->get();

        $hasMorePages = $models->count() > $perPage;

        return (new Paginator(
            $models->take($perPage)->values(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        ))->hasMorePagesWhen($hasMorePages);
    }

    /**
     * Resolve the current pagination values.
     *
     * @return array{int, int, int}
     */
    protected function resolvePaginationState(Builder $builder, ?int $perPage, string $pageName, ?int $page): array
    {
        [$page, $perPage] = [
            max(1, $page ?: Paginator::resolveCurrentPage($pageName)),
            max(1, $perPage ?: $builder->model->getPerPage()),
        ];

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /**
     * Resolve the maximum number of results for the search.
     */
    protected function resolveResultLimit(Builder $builder): int
    {
        return $builder->limit === null
            ? PHP_INT_MAX
            : max(0, $builder->limit);
    }
}
