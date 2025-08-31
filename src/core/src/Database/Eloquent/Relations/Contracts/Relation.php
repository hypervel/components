<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations\Contracts;

use Closure;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TParentModel of \Hypervel\Database\Eloquent\Model
 * @template TResult
 */
interface Relation
{
    public static function noConstraints(Closure $callback);

    /**
     * @param null|array<string, class-string<Model>> $map
     * @param bool $merge
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(?array $map = null, $merge = true);

    /**
     * @param string $alias
     * @return null|class-string<Model>
     */
    public static function getMorphedModel($alias);

    /**
     * @param class-string<Model> $className
     */
    public static function getMorphAlias(string $className): string;

    public static function requireMorphMap(bool $requireMorphMap = true): void;

    public static function requiresMorphMap(): bool;

    /**
     * @param null|array<string, class-string<Model>> $map
     * @return array<string, class-string<Model>>
     */
    public static function enforceMorphMap(?array $map, bool $merge = true): array;

    public function addConstraints();

    /**
     * @param array<int, TParentModel> $models
     */
    public function addEagerConstraints(array $models);

    /**
     * @param array<int, TParentModel> $models
     * @param string $relation
     * @return array<int, TParentModel>
     */
    public function initRelation(array $models, $relation);

    /**
     * @param array<int, TParentModel> $models
     * @param Collection<int, TRelatedModel> $results
     * @param string $relation
     * @return array<int, TParentModel>
     */
    public function match(array $models, Collection $results, $relation);

    /**
     * @return TResult
     */
    public function getResults();

    /**
     * @return Collection<int, TRelatedModel>
     */
    public function getEager();

    /**
     * @param array<int, string>|string $columns
     * @return Collection<int, TRelatedModel>
     */
    public function get($columns = ['*']);

    public function touch();

    /**
     * @param array<string, mixed> $attributes
     * @return int
     */
    public function rawUpdate(array $attributes = []);

    /**
     * @param Builder<TRelatedModel> $query
     * @param Builder<TParentModel> $parentQuery
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceCountQuery(Builder $query, Builder $parentQuery);

    /**
     * @param Builder<TRelatedModel> $query
     * @param Builder<TParentModel> $parentQuery
     * @param array<int, string>|string $columns
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']);

    /**
     * @return Builder<TRelatedModel>
     */
    public function getQuery();

    /**
     * @return \Hyperf\Database\Query\Builder
     */
    public function getBaseQuery();

    /**
     * @return TParentModel
     */
    public function getParent();

    /**
     * @return string
     */
    public function getQualifiedParentKeyName();

    /**
     * @return TRelatedModel
     */
    public function getRelated();

    /**
     * @return string
     */
    public function createdAt();

    /**
     * @return string
     */
    public function updatedAt();

    /**
     * @return string
     */
    public function relatedUpdatedAt();

    /**
     * @return string
     */
    public function getRelationCountHash(bool $incrementJoinCount = true);
}
