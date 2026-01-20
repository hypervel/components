<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Database\Model\Builder;
use Hyperf\Database\Query\Builder as QueryBuilder;

/**
 * @template TBuilder of Builder
 */
trait HasBuilder
{
    /**
     * Begin querying the model.
     *
     * @return TBuilder
     */
    public static function query(): Builder
    {
        return parent::query();
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @return TBuilder
     */
    public function newEloquentBuilder(QueryBuilder $query): Builder
    {
        return parent::newEloquentBuilder($query);
    }

    /**
     * Get a new query builder for the model's table.
     *
     * @return TBuilder
     */
    public function newQuery(): Builder
    {
        return parent::newQuery();
    }

    /**
     * Get a new query builder that doesn't have any global scopes or eager loading.
     *
     * @return TBuilder
     */
    public function newModelQuery(): Builder
    {
        return parent::newModelQuery();
    }

    /**
     * Get a new query builder with no relationships loaded.
     *
     * @return TBuilder
     */
    public function newQueryWithoutRelationships(): Builder
    {
        return parent::newQueryWithoutRelationships();
    }

    /**
     * Get a new query builder that doesn't have any global scopes.
     *
     * @return TBuilder
     */
    public function newQueryWithoutScopes(): Builder
    {
        return parent::newQueryWithoutScopes();
    }

    /**
     * Get a new query instance without a given scope.
     *
     * @param Scope|string $scope
     * @return TBuilder
     */
    public function newQueryWithoutScope($scope): Builder
    {
        return parent::newQueryWithoutScope($scope);
    }

    /**
     * Get a new query to restore one or more models by their queueable IDs.
     *
     * @return TBuilder
     */
    public function newQueryForRestoration(array|int $ids): Builder
    {
        return parent::newQueryForRestoration($ids);
    }

    /**
     * Begin querying the model on a given connection.
     *
     * @return TBuilder
     */
    public static function on(?string $connection = null): Builder
    {
        return parent::on($connection);
    }

    /**
     * Begin querying the model on the write connection.
     *
     * @return TBuilder
     */
    public static function onWriteConnection(): Builder
    {
        return parent::onWriteConnection();
    }

    /**
     * Begin querying a model with eager loading.
     *
     * @return TBuilder
     */
    public static function with(array|string $relations): Builder
    {
        return parent::with($relations);
    }
}
