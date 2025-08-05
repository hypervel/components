<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hyperf\Database\Model\Concerns\HasRelationships as BaseHasRelationships;
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
use Hypervel\Support\Arr;

trait HasRelationships
{
    use BaseHasRelationships {
        hasMany as private baseHasMany;
        hasOne as private baseHasOne;
        belongsTo as private baseBelongsTo;
        belongsToMany as private baseBelongsToMany;
        morphMany as private baseMorphMany;
        morphOne as private baseMorphOne;
        morphTo as private baseMorphTo;
        morphToMany as private baseMorphToMany;
        hasManyThrough as private baseHasManyThrough;
        hasOneThrough as private baseHasOneThrough;
    }

    public static array $overriddenManyMethods = [
        'belongsToMany', 'morphToMany', 'morphedByMany',
        'baseBelongsToMany', 'baseMorphToMany', 'baseMorphedByMany',
    ];

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param null|string $foreignKey
     * @param null|string $localKey
     * @return \Hypervel\Database\Eloquent\Relations\HasMany<TRelatedModel, $this>
     */
    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $relation = $this->baseHasMany($related, $foreignKey, $localKey);

        return new HasMany(
            $relation->getQuery(),
            $relation->getParent(),
            $relation->getForeignKeyName(),
            $relation->getLocalKeyName()
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param null|string $foreignKey
     * @param null|string $localKey
     * @return \Hypervel\Database\Eloquent\Relations\HasOne<TRelatedModel, $this>
     */
    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $relation = $this->baseHasOne($related, $foreignKey, $localKey);

        return new HasOne(
            $relation->getQuery(),
            $relation->getParent(),
            $relation->getForeignKeyName(),
            $relation->getLocalKeyName()
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param null|string $foreignKey
     * @param null|string $ownerKey
     * @param null|string $relation
     * @return \Hypervel\Database\Eloquent\Relations\BelongsTo<TRelatedModel, $this>
     */
    public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null)
    {
        $relation = $this->baseBelongsTo($related, $foreignKey, $ownerKey, $relation);

        return new BelongsTo(
            $relation->getQuery(),
            $relation->getChild(),
            $relation->getForeignKeyName(),
            $relation->getOwnerKeyName(),
            $relation->getRelationName()
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TPivotModel of \Hypervel\Database\Eloquent\Relations\Pivot
     *
     * @param class-string<TRelatedModel> $related
     * @param null|string $table
     * @param null|string $foreignPivotKey
     * @param null|string $relatedPivotKey
     * @param null|string $parentKey
     * @param null|string $relatedKey
     * @param null|string $relation
     * @return \Hypervel\Database\Eloquent\Relations\BelongsToMany<TRelatedModel, $this>
     */
    public function belongsToMany(
        $related,
        $table = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null,
        $relation = null
    ) {
        $relation = $this->baseBelongsToMany($related, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relation);

        return new BelongsToMany(
            $relation->getQuery(),
            $relation->getParent(),
            $relation->getTable(),
            $relation->getForeignPivotKeyName(),
            $relation->getRelatedPivotKeyName(),
            $relation->getParentKeyName(),
            $relation->getRelatedKeyName(),
            $relation->getRelationName()
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param string $name
     * @param null|string $type
     * @param null|string $id
     * @param null|string $localKey
     * @return \Hypervel\Database\Eloquent\Relations\MorphMany<TRelatedModel, $this>
     */
    public function morphMany($related, $name, $type = null, $id = null, $localKey = null)
    {
        $relation = $this->baseMorphMany($related, $name, $type, $id, $localKey);

        return new MorphMany(
            $relation->getQuery(),
            $relation->getParent(),
            $relation->getMorphType(),
            $relation->getForeignKeyName(),
            $relation->getLocalKeyName()
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param string $name
     * @param null|string $type
     * @param null|string $id
     * @param null|string $localKey
     * @return \Hypervel\Database\Eloquent\Relations\MorphOne<TRelatedModel, $this>
     */
    public function morphOne($related, $name, $type = null, $id = null, $localKey = null)
    {
        $relation = $this->baseMorphOne($related, $name, $type, $id, $localKey);

        return new MorphOne(
            $relation->getQuery(),
            $relation->getParent(),
            $relation->getMorphType(),
            $relation->getForeignKeyName(),
            $relation->getLocalKeyName()
        );
    }

    /**
     * @param null|string $name
     * @param null|string $type
     * @param null|string $id
     * @param null|string $ownerKey
     * @return \Hypervel\Database\Eloquent\Relations\MorphTo<\Hypervel\Database\Eloquent\Model, $this>
     */
    public function morphTo($name = null, $type = null, $id = null, $ownerKey = null)
    {
        $relation = $this->baseMorphTo($name, $type, $id, $ownerKey);

        return new MorphTo(
            $relation->getQuery(),
            $relation->getChild(),
            $relation->getForeignKeyName(),
            $relation->getOwnerKeyName(),
            $relation->getMorphType(),
            $relation->getRelationName()
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TPivotModel of \Hypervel\Database\Eloquent\Relations\MorphPivot
     *
     * @param class-string<TRelatedModel> $related
     * @param string $name
     * @param null|string $table
     * @param null|string $foreignPivotKey
     * @param null|string $relatedPivotKey
     * @param null|string $parentKey
     * @param null|string $relatedKey
     * @param bool $inverse
     * @return \Hypervel\Database\Eloquent\Relations\MorphToMany<TRelatedModel, $this>
     */
    public function morphToMany(
        $related,
        $name,
        $table = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null,
        $inverse = false
    ) {
        $relation = $this->baseMorphToMany($related, $name, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $inverse);

        return new MorphToMany(
            $relation->getQuery(),
            $relation->getParent(),
            $name,
            $relation->getTable(),
            $relation->getForeignPivotKeyName(),
            $relation->getRelatedPivotKeyName(),
            $relation->getParentKeyName(),
            $relation->getRelatedKeyName(),
            $relation->getRelationName(),
            $inverse
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TThroughModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param class-string<TThroughModel> $through
     * @param null|string $firstKey
     * @param null|string $secondKey
     * @param null|string $localKey
     * @param null|string $secondLocalKey
     * @return \Hypervel\Database\Eloquent\Relations\HasManyThrough<TRelatedModel, TThroughModel, $this>
     */
    public function hasManyThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
    {
        $relation = $this->baseHasManyThrough($related, $through, $firstKey, $secondKey, $localKey, $secondLocalKey);

        // Get the through parent model instance
        $throughParent = $relation->getParent();

        return new HasManyThrough(
            $relation->getQuery(),
            $this,
            $throughParent,
            $relation->getFirstKeyName(),
            $relation->getForeignKeyName(),
            $relation->getLocalKeyName(),
            $relation->getSecondLocalKeyName()
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TThroughModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TRelatedModel> $related
     * @param class-string<TThroughModel> $through
     * @param null|string $firstKey
     * @param null|string $secondKey
     * @param null|string $localKey
     * @param null|string $secondLocalKey
     * @return \Hypervel\Database\Eloquent\Relations\HasOneThrough<TRelatedModel, TThroughModel, $this>
     */
    public function hasOneThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
    {
        $relation = $this->baseHasOneThrough($related, $through, $firstKey, $secondKey, $localKey, $secondLocalKey);

        // Get the through parent model instance
        $throughParent = $relation->getParent();

        return new HasOneThrough(
            $relation->getQuery(),
            $this,
            $throughParent,
            $relation->getFirstKeyName(),
            $relation->getForeignKeyName(),
            $relation->getLocalKeyName(),
            $relation->getSecondLocalKeyName()
        );
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TPivotModel of \Hypervel\Database\Eloquent\Relations\MorphPivot
     *
     * @param class-string<TRelatedModel> $related
     * @param string $name
     * @param null|string $table
     * @param null|string $foreignPivotKey
     * @param null|string $relatedPivotKey
     * @param null|string $parentKey
     * @param null|string $relatedKey
     * @return \Hypervel\Database\Eloquent\Relations\MorphToMany<TRelatedModel, $this>
     */
    public function morphedByMany(
        $related,
        $name,
        $table = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null
    ) {
        return $this->morphToMany($related, $name, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, true);
    }

    /**
     * @return string
     */
    protected function guessBelongsToRelation()
    {
        [$one, $two, $three, $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        return $caller['function'] ?? $three['function'];
    }

    /**
     * @return null|string
     */
    protected function guessBelongsToManyRelation()
    {
        $caller = Arr::first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), function ($trace) {
            return ! in_array(
                $trace['function'],
                array_merge(static::$overriddenManyMethods, ['guessBelongsToManyRelation'])
            );
        });

        return ! is_null($caller) ? $caller['function'] : null;
    }
}
