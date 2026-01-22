<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use BadMethodCallException;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 *
 * @extends \Hypervel\Database\Eloquent\Relations\BelongsTo<TRelatedModel, TDeclaringModel>
 */
class MorphTo extends BelongsTo
{
    use InteractsWithDictionary;

    /**
     * The type of the polymorphic relation.
     */
    protected string $morphType;

    /**
     * The associated key on the parent model.
     */
    protected ?string $ownerKey;

    /**
     * The models whose relations are being eager loaded.
     *
     * @var \Hypervel\Database\Eloquent\Collection<int, TDeclaringModel>
     */
    protected EloquentCollection $models;

    /**
     * All of the models keyed by ID.
     */
    protected array $dictionary = [];

    /**
     * A buffer of dynamic calls to query macros.
     */
    protected array $macroBuffer = [];

    /**
     * A map of relations to load for each individual morph type.
     */
    protected array $morphableEagerLoads = [];

    /**
     * A map of relationship counts to load for each individual morph type.
     */
    protected array $morphableEagerLoadCounts = [];

    /**
     * A map of constraints to apply for each individual morph type.
     */
    protected array $morphableConstraints = [];

    /**
     * Create a new morph to relationship instance.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     */
    public function __construct(Builder $query, Model $parent, string $foreignKey, ?string $ownerKey, string $type, string $relation)
    {
        $this->morphType = $type;

        parent::__construct($query, $parent, $foreignKey, $ownerKey, $relation);
    }

    /** @inheritDoc */
    #[\Override]
    public function addEagerConstraints(array $models): void
    {
        // @phpstan-ignore argument.type (MorphTo eager loading uses declaring model, not related model)
        $this->buildDictionary($this->models = new EloquentCollection($models));
    }

    /**
     * Build a dictionary with the models.
     *
     * @param  \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>  $models
     */
    protected function buildDictionary(EloquentCollection $models): void
    {
        foreach ($models as $model) {
            if ($model->{$this->morphType}) {
                $morphTypeKey = $this->getDictionaryKey($model->{$this->morphType});
                $foreignKeyKey = $this->getDictionaryKey($model->{$this->foreignKey});

                $this->dictionary[$morphTypeKey][$foreignKeyKey][] = $model;
            }
        }
    }

    /**
     * Get the results of the relationship.
     *
     * Called via eager load method of Eloquent query builder.
     *
     * @return \Hypervel\Database\Eloquent\Collection<int, TDeclaringModel>
     */
    public function getEager(): EloquentCollection
    {
        foreach (array_keys($this->dictionary) as $type) {
            $this->matchToMorphParents($type, $this->getResultsByType($type));
        }

        return $this->models;
    }

    /**
     * Get all of the relation results for a type.
     *
     * @return \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>
     */
    protected function getResultsByType(string $type): EloquentCollection
    {
        $instance = $this->createModelByType($type);

        $ownerKey = $this->ownerKey ?? $instance->getKeyName();

        $query = $this->replayMacros($instance->newQuery())
            ->mergeConstraintsFrom($this->getQuery())
            ->with(array_merge(
                $this->getQuery()->getEagerLoads(),
                (array) ($this->morphableEagerLoads[get_class($instance)] ?? [])
            ))
            ->withCount(
                (array) ($this->morphableEagerLoadCounts[get_class($instance)] ?? [])
            );

        if ($callback = ($this->morphableConstraints[get_class($instance)] ?? null)) {
            $callback($query);
        }

        $whereIn = $this->whereInMethod($instance, $ownerKey);

        return $query->{$whereIn}(
            $instance->qualifyColumn($ownerKey), $this->gatherKeysByType($type, $instance->getKeyType())
        )->get();
    }

    /**
     * Gather all of the foreign keys for a given type.
     */
    protected function gatherKeysByType(string $type, string $keyType): array
    {
        return $keyType !== 'string'
            ? array_keys($this->dictionary[$type])
            : array_map(function ($modelId) {
                return (string) $modelId;
            }, array_filter(array_keys($this->dictionary[$type])));
    }

    /**
     * Create a new model instance by type.
     *
     * @return TRelatedModel
     */
    public function createModelByType(string $type): Model
    {
        $class = Model::getActualClassNameForMorph($type);

        return tap(new $class, function ($instance) {
            if (! $instance->getConnectionName()) {
                $instance->setConnection($this->getConnection()->getName());
            }
        });
    }

    /** @inheritDoc */
    #[\Override]
    public function match(array $models, EloquentCollection $results, string $relation): array
    {
        return $models;
    }

    /**
     * Match the results for a given type to their parents.
     *
     * @param  \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>  $results
     */
    protected function matchToMorphParents(string $type, EloquentCollection $results): void
    {
        foreach ($results as $result) {
            $ownerKey = ! is_null($this->ownerKey) ? $this->getDictionaryKey($result->{$this->ownerKey}) : $result->getKey();

            if (isset($this->dictionary[$type][$ownerKey])) {
                foreach ($this->dictionary[$type][$ownerKey] as $model) {
                    $model->setRelation($this->relationName, $result);
                }
            }
        }
    }

    /**
     * Associate the model instance to the given parent.
     *
     * @param  TRelatedModel|null  $model
     * @return TDeclaringModel
     */
    #[\Override]
    public function associate(Model|string|int|null $model): Model
    {
        if ($model instanceof Model) {
            $foreignKey = $this->ownerKey && $model->{$this->ownerKey}
                ? $this->ownerKey
                : $model->getKeyName();
        }

        $this->parent->setAttribute(
            $this->foreignKey, $model instanceof Model ? $model->{$foreignKey} : null
        );

        $this->parent->setAttribute(
            $this->morphType, $model instanceof Model ? $model->getMorphClass() : null
        );

        return $this->parent->setRelation($this->relationName, $model);
    }

    /**
     * Dissociate previously associated model from the given parent.
     *
     * @return TDeclaringModel
     */
    #[\Override]
    public function dissociate(): Model
    {
        $this->parent->setAttribute($this->foreignKey, null);

        $this->parent->setAttribute($this->morphType, null);

        return $this->parent->setRelation($this->relationName, null);
    }

    /** @inheritDoc */
    #[\Override]
    public function touch(): void
    {
        if (! is_null($this->getParentKey())) {
            parent::touch();
        }
    }

    /** @inheritDoc */
    #[\Override]
    protected function newRelatedInstanceFor(Model $parent): Model
    {
        return $parent->{$this->getRelationName()}()->getRelated()->newInstance();
    }

    /**
     * Get the foreign key "type" name.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Get the dictionary used by the relationship.
     */
    public function getDictionary(): array
    {
        return $this->dictionary;
    }

    /**
     * Specify which relations to load for a given morph type.
     *
     * @return $this
     */
    public function morphWith(array $with): static
    {
        $this->morphableEagerLoads = array_merge(
            $this->morphableEagerLoads, $with
        );

        return $this;
    }

    /**
     * Specify which relationship counts to load for a given morph type.
     *
     * @return $this
     */
    public function morphWithCount(array $withCount): static
    {
        $this->morphableEagerLoadCounts = array_merge(
            $this->morphableEagerLoadCounts, $withCount
        );

        return $this;
    }

    /**
     * Specify constraints on the query for a given morph type.
     *
     * @return $this
     */
    public function constrain(array $callbacks): static
    {
        $this->morphableConstraints = array_merge(
            $this->morphableConstraints, $callbacks
        );

        return $this;
    }

    /**
     * Indicate that soft deleted models should be included in the results.
     *
     * @return $this
     */
    public function withTrashed(): static
    {
        $callback = fn ($query) => $query->hasMacro('withTrashed') ? $query->withTrashed() : $query;

        $this->macroBuffer[] = [
            'method' => 'when',
            'parameters' => [true, $callback],
        ];

        return $this->when(true, $callback);
    }

    /**
     * Indicate that soft deleted models should not be included in the results.
     *
     * @return $this
     */
    public function withoutTrashed(): static
    {
        $callback = fn ($query) => $query->hasMacro('withoutTrashed') ? $query->withoutTrashed() : $query;

        $this->macroBuffer[] = [
            'method' => 'when',
            'parameters' => [true, $callback],
        ];

        return $this->when(true, $callback);
    }

    /**
     * Indicate that only soft deleted models should be included in the results.
     *
     * @return $this
     */
    public function onlyTrashed(): static
    {
        $callback = fn ($query) => $query->hasMacro('onlyTrashed') ? $query->onlyTrashed() : $query;

        $this->macroBuffer[] = [
            'method' => 'when',
            'parameters' => [true, $callback],
        ];

        return $this->when(true, $callback);
    }

    /**
     * Replay stored macro calls on the actual related instance.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<TRelatedModel>  $query
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    protected function replayMacros(Builder $query): Builder
    {
        foreach ($this->macroBuffer as $macro) {
            $query->{$macro['method']}(...$macro['parameters']);
        }

        return $query;
    }

    /** @inheritDoc */
    #[\Override]
    public function getQualifiedOwnerKeyName(): string
    {
        if (is_null($this->ownerKey)) {
            return '';
        }

        return parent::getQualifiedOwnerKeyName();
    }

    /**
     * Handle dynamic method calls to the relationship.
     */
    public function __call(string $method, array $parameters): mixed
    {
        try {
            $result = parent::__call($method, $parameters);

            if (in_array($method, ['select', 'selectRaw', 'selectSub', 'addSelect', 'withoutGlobalScopes'])) {
                $this->macroBuffer[] = compact('method', 'parameters');
            }

            return $result;
        }

        // If we tried to call a method that does not exist on the parent Builder instance,
        // we'll assume that we want to call a query macro (e.g. withTrashed) that only
        // exists on related models. We will just store the call and replay it later.
        catch (BadMethodCallException) {
            $this->macroBuffer[] = compact('method', 'parameters');

            return $this;
        }
    }
}
