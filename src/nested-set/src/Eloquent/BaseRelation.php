<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Closure;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Query\Builder;
use Hypervel\NestedSet\NestedSet;
use InvalidArgumentException;
use LogicException;

abstract class BaseRelation extends Relation
{
    /**
     * The maximum number of "or" constraints chained in one eager constraint group.
     */
    protected const int EAGER_CONSTRAINT_GROUP_SIZE = 64;

    /**
     * The nested-set query builder instance.
     *
     * @var QueryBuilder
     */
    protected EloquentBuilder $query;

    /**
     * Create a new nested set relation.
     */
    public function __construct(QueryBuilder $builder, Model $model)
    {
        if (! NestedSet::isNode($model)) {
            throw new InvalidArgumentException('Model must be node.');
        }

        parent::__construct($builder, $model);
    }

    /**
     * Constrain the eager query to the prepared parent models.
     */
    abstract protected function constrainEagerModels(Builder $query, array $models): void;

    /**
     * Match eager results to persisted parents on the results' connection and table.
     *
     * Aimeos matches one parent at a time through indexResults() and
     * matchFromIndex(); this hook receives every parent so a relation can
     * match them together.
     *
     * @return array<int, list<Model>> related models keyed by the parent's object ID
     */
    abstract protected function matchMany(array $models, Collection $results): array;

    /**
     * Get the relation existence condition.
     */
    abstract protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string;

    /**
     * Get columns required from a persisted parent model.
     *
     * @return array<string, bool> columns keyed by name; true when null is invalid
     */
    abstract protected function requiredParentColumns(Model $model): array;

    /**
     * Get columns required from an eagerly loaded related model.
     *
     * @return array<string, bool> columns keyed by name; true when null is invalid
     */
    abstract protected function requiredRelatedColumns(Model $model): array;

    /**
     * Get the relation existence query.
     */
    public function getRelationExistenceQuery(EloquentBuilder $query, EloquentBuilder $parentQuery, mixed $columns = ['*']): EloquentBuilder
    {
        $parent = $this->getParent();

        // Start from the class-default table so a relation alias cannot become
        // a FROM source; Eloquent applies caller constraints afterward.
        $model = new ($parent::class);
        $model->setConnection($parent->getConnectionName());

        $query = $model->newQuery();
        $query->select($columns);

        $table = $query->getModel()->getTable();
        $hash = $this->getRelationCountHash();

        $query->from($table . ' as ' . $hash);
        $query->getModel()->setTable($hash);

        $grammar = $query->getQuery()->getGrammar();

        $condition = $this->relationExistenceCondition(
            $grammar->wrapTable($hash),
            $grammar->wrapTable($parentQuery->getModel()->getTable()),
            $grammar->wrap($this->parent->getLftName()), /* @phpstan-ignore method.notFound */
            $grammar->wrap($this->parent->getRgtName()) /* @phpstan-ignore method.notFound */
        );

        $query->whereRaw($condition);

        foreach (array_keys($this->scopeValues($this->parent)) as $attribute) {
            $relatedColumn = $hash . '.' . $attribute;
            $parentColumn = $parentQuery->getModel()->getTable() . '.' . $attribute;

            $query->where(function (EloquentBuilder $query) use ($relatedColumn, $parentColumn) {
                $query->whereColumn($relatedColumn, '=', $parentColumn)
                    ->orWhere(function (EloquentBuilder $query) use ($relatedColumn, $parentColumn) {
                        $query->whereNull($relatedColumn)
                            ->whereNull($parentColumn);
                    });
            });
        }

        return $query;
    }

    /**
     * Initialize the relation on a set of models.
     */
    public function initRelation(array $models, string $relation): array
    {
        return $models;
    }

    /**
     * Get a relationship join table hash.
     */
    public function getRelationCountHash(bool $incrementJoinCount = true): string
    {
        return 'nested_set_' . ($incrementJoinCount ? static::$selfJoinCount++ : static::$selfJoinCount);
    }

    /**
     * Get the results of the relationship.
     */
    public function getResults(): Collection
    {
        if (! $this->parent->exists) {
            return $this->related->newCollection();
        }

        return $this->query->get();
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        $models = $this->prepareEagerModels($models);

        if ($models === []) {
            $this->eagerKeysWereEmpty = true;

            return;
        }

        $this->query->whereNested(function (Builder $inner) use ($models): void {
            $this->constrainEagerModels($inner, $models);
        });
    }

    /**
     * Match the eagerly loaded results to their parents.
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        foreach ($results as $related) {
            $this->ensureProjection(
                $related,
                $this->requiredRelatedColumns($related),
                'eager load',
            );
        }

        $matches = [];

        if ($results->isNotEmpty()) {
            $identity = NestedSet::structuralIdentity($results->first());
            $persisted = array_values(array_filter(
                $models,
                static fn (Model $model): bool => $model->exists
                    && NestedSet::structuralIdentity($model) === $identity,
            ));

            if ($persisted !== []) {
                $matches = $this->matchMany($persisted, $results);
            }
        }

        foreach ($models as $model) {
            $model->setRelation(
                $relation,
                $this->related->newCollection($matches[spl_object_id($model)] ?? []),
            );
        }

        return $models;
    }

    /**
     * Add constraints as balanced nested "or" groups.
     *
     * SQLite parses each chained "or" as one more level of expression depth and
     * rejects expressions deeper than 1,000 levels, so long eager constraint
     * lists are split into bounded groups instead of one chain. The callback
     * adds one "or" constraint for an item.
     */
    protected function addBalancedOrConstraints(Builder $query, array $items, Closure $constraint): void
    {
        $size = static::EAGER_CONSTRAINT_GROUP_SIZE;

        if (count($items) <= $size) {
            foreach ($items as $item) {
                $constraint($query, $item);
            }

            return;
        }

        $chunkSize = $size;

        while (count($items) > $chunkSize * $size) {
            $chunkSize *= $size;
        }

        foreach (array_chunk($items, $chunkSize) as $chunk) {
            $query->whereNested(function (Builder $query) use ($chunk, $constraint): void {
                $this->addBalancedOrConstraints($query, $chunk, $constraint);
            }, 'or');
        }
    }

    /**
     * Deduplicate the parent models used to constrain an eager query.
     */
    protected function prepareEagerModels(array $models): array
    {
        $result = [];
        $seenKeys = [];
        $seenObjects = [];

        foreach ($models as $model) {
            if (! $model->exists) {
                continue;
            }

            $this->ensureProjection(
                $model,
                $this->requiredParentColumns($model),
                'parent',
            );

            $scope = $this->scopeKey($model);
            $key = $model->getKey();

            if ($key === null) {
                $objectId = spl_object_id($model);

                if (isset($seenObjects[$scope][$objectId])) {
                    continue;
                }

                $seenObjects[$scope][$objectId] = true;
            } else {
                if (isset($seenKeys[$scope][$key])) {
                    continue;
                }

                $seenKeys[$scope][$key] = true;
            }

            $result[] = $model;
        }

        return $result;
    }

    /**
     * Prepare the lazy parent or constrain an unsaved parent to no results.
     */
    protected function prepareLazyParent(): bool
    {
        if (! $this->parent->exists) {
            $this->query->whereRaw('0 = 1');

            return false;
        }

        $this->ensureProjection(
            $this->parent,
            $this->requiredParentColumns($this->parent),
            'parent',
        );

        return true;
    }

    /**
     * Ensure a persisted relation model contains its required projection.
     */
    protected function ensureProjection(Model $model, array $columns, string $context): void
    {
        $attributes = $model->getAttributes();

        foreach ($columns as $column => $requiresValue) {
            if (! array_key_exists($column, $attributes)
                || ($requiresValue && $attributes[$column] === null)
            ) {
                throw new LogicException(sprintf(
                    'Nested set relation %s for [%s] requires the [%s] column.',
                    $context,
                    $model::class,
                    $column,
                ));
            }
        }
    }

    /**
     * Group eager results by scope in left-bound order, keeping their query positions.
     *
     * @return array<string, array{models: list<Model>, keys: list<null|string>, lfts: list<int>, rgts: list<int>, positions: list<int>, ordered: bool}>
     */
    protected function sortedResultBuckets(Collection $results, bool $includeRgt): array
    {
        $buckets = [];
        $position = 0;

        foreach ($results as $related) {
            $scope = $this->scopeKey($related);
            $buckets[$scope]['models'][] = $related;
            $buckets[$scope]['keys'][] = $this->matchKey($related);
            $buckets[$scope]['lfts'][] = $related->getLft(); /* @phpstan-ignore method.notFound */
            $buckets[$scope]['rgts'] ??= [];

            if ($includeRgt) {
                $buckets[$scope]['rgts'][] = $related->getRgt(); /* @phpstan-ignore method.notFound */
            }

            $buckets[$scope]['positions'][] = $position++;
        }

        foreach ($buckets as $scope => $bucket) {
            $bucket['ordered'] = true;

            for ($index = 1, $count = count($bucket['lfts']); $index < $count; ++$index) {
                if ($bucket['lfts'][$index] < $bucket['lfts'][$index - 1]) {
                    $bucket['ordered'] = false;

                    break;
                }
            }

            if (! $bucket['ordered']) {
                // Query positions are unique, so ties never compare the models.
                $includeRgt
                    ? array_multisort($bucket['lfts'], $bucket['positions'], $bucket['rgts'], $bucket['keys'], $bucket['models'])
                    : array_multisort($bucket['lfts'], $bucket['positions'], $bucket['keys'], $bucket['models']);
            }

            $buckets[$scope] = $bucket;
        }

        return $buckets;
    }

    /**
     * Restore query order among matched bucket entries when the bucket was re-sorted.
     *
     * @param list<int> $matches bucket indexes in left-bound order
     * @return list<Model>
     */
    protected function bucketModels(array $bucket, array $matches): array
    {
        if (! $bucket['ordered']) {
            usort(
                $matches,
                static fn (int $left, int $right): int => $bucket['positions'][$left] <=> $bucket['positions'][$right],
            );
        }

        return array_map(static fn (int $index): Model => $bucket['models'][$index], $matches);
    }

    /**
     * Get a model key in the string form used to recognize a parent's own row.
     */
    protected function matchKey(Model $model): ?string
    {
        $key = $model->getKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * Get the normalized nested-set scope values for a node.
     *
     * @return array<string, null|int|string>
     */
    protected function scopeValues(Model $model): array
    {
        return $model->getNestedSetScope(); /* @phpstan-ignore method.notFound */
    }

    /**
     * Get the stable identity key for a node's nested-set scope.
     */
    protected function scopeKey(Model $model): string
    {
        return $model->getNestedSetScopeKey(); /* @phpstan-ignore method.notFound */
    }

    /**
     * Get the plain foreign key.
     */
    public function getForeignKeyName(): string
    {
        return $this->parent->getParentIdName(); /* @phpstan-ignore method.notFound */
    }

    /**
     * Get the qualified foreign key name.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->related->qualifyColumn($this->getForeignKeyName());
    }
}
