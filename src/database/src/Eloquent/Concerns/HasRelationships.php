<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Closure;
use Hypervel\Database\ClassMorphViolationException;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\PendingHasThroughRelationship;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\HasOneThrough;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Support\Arr;
use Hypervel\Support\StrCache;

trait HasRelationships
{
    /**
     * The loaded relationships for the model.
     */
    protected array $relations = [];

    /**
     * The relationships that should be touched on save.
     */
    protected array $touches = [];

    /**
     * The relationship autoloader callback.
     */
    protected ?Closure $relationAutoloadCallback = null;

    /**
     * The relationship autoloader callback context.
     */
    protected mixed $relationAutoloadContext = null;

    /**
     * The many to many relationship methods.
     *
     * @var string[]
     */
    public static array $manyMethods = [
        'belongsToMany', 'morphToMany', 'morphedByMany',
    ];

    /**
     * The relation resolver callbacks.
     */
    protected static array $relationResolvers = [];

    /**
     * Get the dynamic relation resolver if defined or inherited, or return null.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $class
     */
    public function relationResolver(string $class, string $key): ?Closure
    {
        if ($resolver = static::$relationResolvers[$class][$key] ?? null) {
            return $resolver;
        }

        if ($parent = get_parent_class($class)) {
            return $this->relationResolver($parent, $key);
        }

        return null;
    }

    /**
     * Define a dynamic relation resolver.
     */
    public static function resolveRelationUsing(string $name, Closure $callback): void
    {
        static::$relationResolvers = array_replace_recursive(
            static::$relationResolvers,
            [static::class => [$name => $callback]]
        );
    }

    /**
     * Determine if a relationship autoloader callback has been defined.
     */
    public function hasRelationAutoloadCallback(): bool
    {
        return ! is_null($this->relationAutoloadCallback);
    }

    /**
     * Define an automatic relationship autoloader callback for this model and its relations.
     */
    public function autoloadRelationsUsing(Closure $callback, mixed $context = null): static
    {
        // Prevent circular relation autoloading...
        if ($context && $this->relationAutoloadContext === $context) {
            return $this;
        }

        $this->relationAutoloadCallback = $callback;
        $this->relationAutoloadContext = $context;

        foreach ($this->relations as $key => $value) {
            $this->propagateRelationAutoloadCallbackToRelation($key, $value);
        }

        return $this;
    }

    /**
     * Attempt to autoload the given relationship using the autoload callback.
     */
    protected function attemptToAutoloadRelation(string $key): bool
    {
        if (! $this->hasRelationAutoloadCallback()) {
            return false;
        }

        $this->invokeRelationAutoloadCallbackFor($key, []);

        return $this->relationLoaded($key);
    }

    /**
     * Invoke the relationship autoloader callback for the given relationships.
     */
    protected function invokeRelationAutoloadCallbackFor(string $key, array $tuples): void
    {
        $tuples = array_merge([[$key, get_class($this)]], $tuples);

        call_user_func($this->relationAutoloadCallback, $tuples);
    }

    /**
     * Propagate the relationship autoloader callback to the given related models.
     */
    protected function propagateRelationAutoloadCallbackToRelation(string $key, mixed $models): void
    {
        if (! $this->hasRelationAutoloadCallback() || ! $models) {
            return;
        }

        if ($models instanceof Model) {
            $models = [$models];
        }

        if (! is_iterable($models)) {
            return;
        }

        $callback = fn (array $tuples) => $this->invokeRelationAutoloadCallbackFor($key, $tuples);

        foreach ($models as $model) {
            $model->autoloadRelationsUsing($callback, $this->relationAutoloadContext);
        }
    }

    /**
     * Define a one-to-one relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @return \Hypervel\Database\Eloquent\Relations\HasOne<TRelatedModel, $this>
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasOne($instance->newQuery(), $this, $instance->qualifyColumn($foreignKey), $localKey);
    }

    /**
     * Instantiate a new HasOne relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @return \Hypervel\Database\Eloquent\Relations\HasOne<TRelatedModel, TDeclaringModel>
     */
    protected function newHasOne(Builder $query, Model $parent, string $foreignKey, string $localKey): HasOne
    {
        return new HasOne($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Define a has-one-through relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param class-string<TIntermediateModel> $through
     * @return \Hypervel\Database\Eloquent\Relations\HasOneThrough<TRelatedModel, TIntermediateModel, $this>
     */
    public function hasOneThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasOneThrough
    {
        $through = $this->newRelatedThroughInstance($through);

        $firstKey = $firstKey ?: $this->getForeignKey();

        $secondKey = $secondKey ?: $through->getForeignKey();

        return $this->newHasOneThrough(
            $this->newRelatedInstance($related)->newQuery(),
            $this,
            $through,
            $firstKey,
            $secondKey,
            $localKey ?: $this->getKeyName(),
            $secondLocalKey ?: $through->getKeyName(),
        );
    }

    /**
     * Instantiate a new HasOneThrough relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $farParent
     * @param TIntermediateModel $throughParent
     * @return \Hypervel\Database\Eloquent\Relations\HasOneThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     */
    protected function newHasOneThrough(Builder $query, Model $farParent, Model $throughParent, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey): HasOneThrough
    {
        return new HasOneThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Define a polymorphic one-to-one relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @return \Hypervel\Database\Eloquent\Relations\MorphOne<TRelatedModel, $this>
     */
    public function morphOne(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphOne
    {
        $instance = $this->newRelatedInstance($related);

        [$type, $id] = $this->getMorphs($name, $type, $id);

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newMorphOne($instance->newQuery(), $this, $instance->qualifyColumn($type), $instance->qualifyColumn($id), $localKey);
    }

    /**
     * Instantiate a new MorphOne relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @return \Hypervel\Database\Eloquent\Relations\MorphOne<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphOne(Builder $query, Model $parent, string $type, string $id, string $localKey): MorphOne
    {
        return new MorphOne($query, $parent, $type, $id, $localKey);
    }

    /**
     * Define an inverse one-to-one or many relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @return \Hypervel\Database\Eloquent\Relations\BelongsTo<TRelatedModel, $this>
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relation = null): BelongsTo
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (is_null($relation)) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        // If no foreign key was supplied, we can use a backtrace to guess the proper
        // foreign key name by using the name of the relationship function, which
        // when combined with an "_id" should conventionally match the columns.
        if (is_null($foreignKey)) {
            $foreignKey = StrCache::snake($relation) . '_' . $instance->getKeyName();
        }

        // Once we have the foreign key names we'll just create a new Eloquent query
        // for the related models and return the relationship instance which will
        // actually be responsible for retrieving and hydrating every relation.
        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return $this->newBelongsTo(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation
        );
    }

    /**
     * Instantiate a new BelongsTo relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $child
     * @return \Hypervel\Database\Eloquent\Relations\BelongsTo<TRelatedModel, TDeclaringModel>
     */
    protected function newBelongsTo(Builder $query, Model $child, string $foreignKey, string $ownerKey, string $relation): BelongsTo
    {
        return new BelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Define a polymorphic, inverse one-to-one or many relationship.
     *
     * @return \Hypervel\Database\Eloquent\Relations\MorphTo<\Hypervel\Database\Eloquent\Model, $this>
     */
    public function morphTo(?string $name = null, ?string $type = null, ?string $id = null, ?string $ownerKey = null): MorphTo
    {
        // If no name is provided, we will use the backtrace to get the function name
        // since that is most likely the name of the polymorphic interface. We can
        // use that to get both the class and foreign key that will be utilized.
        $name = $name ?: $this->guessBelongsToRelation();

        [$type, $id] = $this->getMorphs(
            StrCache::snake($name),
            $type,
            $id
        );

        // If the type value is null it is probably safe to assume we're eager loading
        // the relationship. In this case we'll just pass in a dummy query where we
        // need to remove any eager loads that may already be defined on a model.
        return is_null($class = $this->getAttributeFromArray($type)) || $class === ''
            ? $this->morphEagerTo($name, $type, $id, $ownerKey)
            : $this->morphInstanceTo($class, $name, $type, $id, $ownerKey);
    }

    /**
     * Define a polymorphic, inverse one-to-one or many relationship.
     *
     * @return \Hypervel\Database\Eloquent\Relations\MorphTo<\Hypervel\Database\Eloquent\Model, $this>
     */
    protected function morphEagerTo(string $name, string $type, string $id, ?string $ownerKey): MorphTo
    {
        // @phpstan-ignore return.type (MorphTo<Model, $this> vs MorphTo<static, $this> - template covariance)
        return $this->newMorphTo(
            $this->newQuery()->setEagerLoads([]),
            $this,
            $id,
            $ownerKey,
            $type,
            $name
        );
    }

    /**
     * Define a polymorphic, inverse one-to-one or many relationship.
     *
     * @return \Hypervel\Database\Eloquent\Relations\MorphTo<\Hypervel\Database\Eloquent\Model, $this>
     */
    protected function morphInstanceTo(string|int $target, string $name, string $type, string $id, ?string $ownerKey): MorphTo
    {
        $instance = $this->newRelatedInstance(
            static::getActualClassNameForMorph($target)
        );

        return $this->newMorphTo(
            $instance->newQuery(),
            $this,
            $id,
            $ownerKey ?? $instance->getKeyName(),
            $type,
            $name
        );
    }

    /**
     * Instantiate a new MorphTo relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @return \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphTo(Builder $query, Model $parent, string $foreignKey, ?string $ownerKey, string $type, string $relation): MorphTo
    {
        return new MorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    /**
     * Retrieve the actual class name for a given morph class.
     */
    public static function getActualClassNameForMorph(string|int $class): string
    {
        return Arr::get(Relation::morphMap() ?: [], $class, (string) $class);
    }

    /**
     * Guess the "belongs to" relationship name.
     */
    protected function guessBelongsToRelation(): string
    {
        [, , $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $caller['function'];
    }

    /**
     * Create a pending has-many-through or has-one-through relationship.
     *
     * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Relations\HasMany<TIntermediateModel, covariant $this>|\Hypervel\Database\Eloquent\Relations\HasOne<TIntermediateModel, covariant $this>|string $relationship
     * @return (
     *     $relationship is string
     *     ? \Hypervel\Database\Eloquent\PendingHasThroughRelationship<\Hypervel\Database\Eloquent\Model, $this>
     *     : (
     *          $relationship is \Hypervel\Database\Eloquent\Relations\HasMany<TIntermediateModel, $this>
     *          ? \Hypervel\Database\Eloquent\PendingHasThroughRelationship<TIntermediateModel, $this, \Hypervel\Database\Eloquent\Relations\HasMany<TIntermediateModel, $this>>
     *          : \Hypervel\Database\Eloquent\PendingHasThroughRelationship<TIntermediateModel, $this, \Hypervel\Database\Eloquent\Relations\HasOne<TIntermediateModel, $this>>
     *     )
     * )
     * @phpstan-ignore conditionalType.alwaysFalse (template covariance limitation with conditional return types)
     */
    public function through(string|HasMany|HasOne $relationship): PendingHasThroughRelationship
    {
        if (is_string($relationship)) {
            $relationship = $this->{$relationship}();
        }

        // @phpstan-ignore return.type (template covariance with $this vs static in PendingHasThroughRelationship)
        return new PendingHasThroughRelationship($this, $relationship);
    }

    /**
     * Define a one-to-many relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @return \Hypervel\Database\Eloquent\Relations\HasMany<TRelatedModel, $this>
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn($foreignKey),
            $localKey
        );
    }

    /**
     * Instantiate a new HasMany relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @return \Hypervel\Database\Eloquent\Relations\HasMany<TRelatedModel, TDeclaringModel>
     */
    protected function newHasMany(Builder $query, Model $parent, string $foreignKey, string $localKey): HasMany
    {
        return new HasMany($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Define a has-many-through relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param class-string<TIntermediateModel> $through
     * @return \Hypervel\Database\Eloquent\Relations\HasManyThrough<TRelatedModel, TIntermediateModel, $this>
     */
    public function hasManyThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasManyThrough
    {
        $through = $this->newRelatedThroughInstance($through);

        $firstKey = $firstKey ?: $this->getForeignKey();

        $secondKey = $secondKey ?: $through->getForeignKey();

        return $this->newHasManyThrough(
            $this->newRelatedInstance($related)->newQuery(),
            $this,
            $through,
            $firstKey,
            $secondKey,
            $localKey ?: $this->getKeyName(),
            $secondLocalKey ?: $through->getKeyName()
        );
    }

    /**
     * Instantiate a new HasManyThrough relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $farParent
     * @param TIntermediateModel $throughParent
     * @return \Hypervel\Database\Eloquent\Relations\HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     */
    protected function newHasManyThrough(Builder $query, Model $farParent, Model $throughParent, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey): HasManyThrough
    {
        return new HasManyThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Define a polymorphic one-to-many relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @return \Hypervel\Database\Eloquent\Relations\MorphMany<TRelatedModel, $this>
     */
    public function morphMany(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphMany
    {
        $instance = $this->newRelatedInstance($related);

        // Here we will gather up the morph type and ID for the relationship so that we
        // can properly query the intermediate table of a relation. Finally, we will
        // get the table and create the relationship instances for the developers.
        [$type, $id] = $this->getMorphs($name, $type, $id);

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newMorphMany($instance->newQuery(), $this, $instance->qualifyColumn($type), $instance->qualifyColumn($id), $localKey);
    }

    /**
     * Instantiate a new MorphMany relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @return \Hypervel\Database\Eloquent\Relations\MorphMany<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphMany(Builder $query, Model $parent, string $type, string $id, string $localKey): MorphMany
    {
        return new MorphMany($query, $parent, $type, $id, $localKey);
    }

    /**
     * Define a many-to-many relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param null|class-string<\Hypervel\Database\Eloquent\Model>|string $table
     * @return \Hypervel\Database\Eloquent\Relations\BelongsToMany<TRelatedModel, $this, \Hypervel\Database\Eloquent\Relations\Pivot>
     */
    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
    ): BelongsToMany {
        // If no relationship name was passed, we will pull backtraces to get the
        // name of the calling function. We will use that function name as the
        // title of this relation since that is a great convention to apply.
        if (is_null($relation)) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // First, we'll need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we'll make the query
        // instances as well as the relationship instances we need for this.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // If no table name was provided, we can guess it by concatenating the two
        // models using underscores in alphabetical order. The two model names
        // are transformed to snake case from their default CamelCase also.
        if (is_null($table)) {
            $table = $this->joiningTable($related, $instance);
        }

        return $this->newBelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation,
        );
    }

    /**
     * Instantiate a new BelongsToMany relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @param class-string<\Hypervel\Database\Eloquent\Model>|string $table
     * @return \Hypervel\Database\Eloquent\Relations\BelongsToMany<TRelatedModel, TDeclaringModel, \Hypervel\Database\Eloquent\Relations\Pivot>
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
    ): BelongsToMany {
        return new BelongsToMany($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    /**
     * Define a polymorphic many-to-many relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @return \Hypervel\Database\Eloquent\Relations\MorphToMany<TRelatedModel, $this>
     */
    public function morphToMany(
        string $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
        bool $inverse = false,
    ): MorphToMany {
        $relation = $relation ?: $this->guessBelongsToManyRelation();

        // First, we will need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we will make the query
        // instances, as well as the relationship instances we need for these.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $name . '_id';

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // Now we're ready to create a new query builder for the related model and
        // the relationship instances for this relation. This relation will set
        // appropriate query constraints then entirely manage the hydrations.
        if (! $table) {
            $words = preg_split('/(_)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE);

            $lastWord = array_pop($words);

            $table = implode('', $words) . StrCache::plural($lastWord);
        }

        return $this->newMorphToMany(
            $instance->newQuery(),
            $this,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation,
            $inverse,
        );
    }

    /**
     * Instantiate a new MorphToMany relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @return \Hypervel\Database\Eloquent\Relations\MorphToMany<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        string $name,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
        bool $inverse = false,
    ): MorphToMany {
        return new MorphToMany(
            $query,
            $parent,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
            $inverse,
        );
    }

    /**
     * Define a polymorphic, inverse many-to-many relationship.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @return \Hypervel\Database\Eloquent\Relations\MorphToMany<TRelatedModel, $this>
     */
    public function morphedByMany(
        string $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
    ): MorphToMany {
        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        // For the inverse of the polymorphic many-to-many relations, we will change
        // the way we determine the foreign and other keys, as it is the opposite
        // of the morph-to-many method since we're figuring out these inverses.
        $relatedPivotKey = $relatedPivotKey ?: $name . '_id';

        return $this->morphToMany(
            $related,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relation,
            true,
        );
    }

    /**
     * Get the relationship name of the belongsToMany relationship.
     */
    protected function guessBelongsToManyRelation(): ?string
    {
        $caller = Arr::first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), function ($trace) {
            return ! in_array(
                $trace['function'],
                array_merge(static::$manyMethods, ['guessBelongsToManyRelation'])
            );
        });

        return ! is_null($caller) ? $caller['function'] : null;
    }

    /**
     * Get the joining table name for a many-to-many relation.
     */
    public function joiningTable(string $related, ?Model $instance = null): string
    {
        // The joining table name, by convention, is simply the snake cased models
        // sorted alphabetically and concatenated with an underscore, so we can
        // just sort the models and join them together to get the table name.
        $segments = [
            $instance
                ? $instance->joiningTableSegment()
                : StrCache::snake(class_basename($related)),
            $this->joiningTableSegment(),
        ];

        // Now that we have the model names in an array we can just sort them and
        // use the implode function to join them together with an underscores,
        // which is typically used by convention within the database system.
        sort($segments);

        return strtolower(implode('_', $segments));
    }

    /**
     * Get this model's half of the intermediate table name for belongsToMany relationships.
     */
    public function joiningTableSegment(): string
    {
        return StrCache::snake(class_basename($this));
    }

    /**
     * Determine if the model touches a given relation.
     */
    public function touches(string $relation): bool
    {
        return in_array($relation, $this->getTouchedRelations());
    }

    /**
     * Touch the owning relations of the model.
     */
    public function touchOwners(): void
    {
        $this->withoutRecursion(function () {
            foreach ($this->getTouchedRelations() as $relation) {
                $this->{$relation}()->touch();

                if ($this->{$relation} instanceof self) {
                    $this->{$relation}->fireModelEvent('saved', false);

                    $this->{$relation}->touchOwners();
                } elseif ($this->{$relation} instanceof EloquentCollection) {
                    $this->{$relation}->each->touchOwners();
                }
            }
        });
    }

    /**
     * Get the polymorphic relationship columns.
     *
     * @return array{0: string, 1: string}
     */
    protected function getMorphs(string $name, ?string $type, ?string $id): array
    {
        return [$type ?: $name . '_type', $id ?: $name . '_id'];
    }

    /**
     * Get the class name for polymorphic relations.
     */
    public function getMorphClass(): string
    {
        $morphMap = Relation::morphMap();

        if (! empty($morphMap) && in_array(static::class, $morphMap)) {
            return array_search(static::class, $morphMap, true);
        }

        if (static::class === Pivot::class) {
            return static::class;
        }

        if (Relation::requiresMorphMap()) {
            throw new ClassMorphViolationException($this);
        }

        return static::class;
    }

    /**
     * Create a new model instance for a related model.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $class
     * @return TRelatedModel
     */
    protected function newRelatedInstance(string $class): Model
    {
        return tap(new $class(), function ($instance) {
            if (! $instance->getConnectionName()) {
                $instance->setConnection($this->connection);
            }
        });
    }

    /**
     * Create a new model instance for a related "through" model.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $class
     * @return TRelatedModel
     */
    protected function newRelatedThroughInstance(string $class): Model
    {
        return new $class();
    }

    /**
     * Get all the loaded relations for the instance.
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Get a specified relationship.
     */
    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation];
    }

    /**
     * Determine if the given relation is loaded.
     */
    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Set the given relationship on the model.
     *
     * @return $this
     */
    public function setRelation(string $relation, mixed $value): static
    {
        $this->relations[$relation] = $value;

        $this->propagateRelationAutoloadCallbackToRelation($relation, $value);

        return $this;
    }

    /**
     * Unset a loaded relationship.
     *
     * @return $this
     */
    public function unsetRelation(string $relation): static
    {
        unset($this->relations[$relation]);

        return $this;
    }

    /**
     * Set the entire relations array on the model.
     *
     * @return $this
     */
    public function setRelations(array $relations): static
    {
        $this->relations = $relations;

        return $this;
    }

    /**
     * Enable relationship autoloading for this model.
     *
     * @return $this
     */
    public function withRelationshipAutoloading(): static
    {
        $this->newCollection([$this])->withRelationshipAutoloading();

        return $this;
    }

    /**
     * Duplicate the instance and unset all the loaded relations.
     *
     * @return $this
     */
    public function withoutRelations(): static
    {
        $model = clone $this;

        return $model->unsetRelations();
    }

    /**
     * Unset all the loaded relations for the instance.
     *
     * @return $this
     */
    public function unsetRelations(): static
    {
        $this->relations = [];

        return $this;
    }

    /**
     * Get the relationships that are touched on save.
     */
    public function getTouchedRelations(): array
    {
        return $this->touches;
    }

    /**
     * Set the relationships that are touched on save.
     *
     * @return $this
     */
    public function setTouchedRelations(array $touches): static
    {
        $this->touches = $touches;

        return $this;
    }
}
