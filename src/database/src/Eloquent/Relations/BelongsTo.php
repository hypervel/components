<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Concerns\ComparesRelatedModels;
use Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Hypervel\Database\Eloquent\Relations\Concerns\SupportsDefaultModels;

use function Hypervel\Support\enum_value;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 *
 * @extends \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, TDeclaringModel, ?TRelatedModel>
 */
class BelongsTo extends Relation
{
    use ComparesRelatedModels;
    use InteractsWithDictionary;
    use SupportsDefaultModels;

    /**
     * The child model instance of the relation.
     *
     * @var TDeclaringModel
     */
    protected Model $child;

    /**
     * The foreign key of the parent model.
     */
    protected string $foreignKey;

    /**
     * The associated key on the parent model.
     */
    protected ?string $ownerKey;

    /**
     * The name of the relationship.
     */
    protected string $relationName;

    /**
     * Create a new belongs to relationship instance.
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $child
     */
    public function __construct(Builder $query, Model $child, string $foreignKey, ?string $ownerKey, string $relationName)
    {
        $this->ownerKey = $ownerKey;
        $this->relationName = $relationName;
        $this->foreignKey = $foreignKey;

        // In the underlying base relationship class, this variable is referred to as
        // the "parent" since most relationships are not inversed. But, since this
        // one is we will create a "child" variable for much better readability.
        $this->child = $child;

        parent::__construct($query, $child);
    }

    public function getResults()
    {
        if (is_null($this->getForeignKeyFrom($this->child))) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->query->first() ?: $this->getDefaultFor($this->parent);
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (static::shouldAddConstraints()) {
            // For belongs to relationships, which are essentially the inverse of has one
            // or has many relationships, we need to actually query on the primary key
            // of the related models matching on the foreign key that's on a parent.
            $key = $this->getQualifiedOwnerKeyName();

            $this->query->where($key, '=', $this->getForeignKeyFrom($this->child));
        }
    }

    public function addEagerConstraints(array $models): void
    {
        // We'll grab the primary key name of the related models since it could be set to
        // a non-standard name and not "id". We will then construct the constraint for
        // our eagerly loading query so it returns the proper models from execution.
        $key = $this->getQualifiedOwnerKeyName();

        $whereIn = $this->whereInMethod($this->related, $this->ownerKey);

        $this->whereInEager($whereIn, $key, $this->getEagerModelKeys($models));
    }

    /**
     * Gather the keys from an array of related models.
     *
     * @param array<int, TDeclaringModel> $models
     */
    protected function getEagerModelKeys(array $models): array
    {
        $keys = [];

        // First we need to gather all of the keys from the parent models so we know what
        // to query for via the eager loading query. We will add them to an array then
        // execute a "where in" statement to gather up all of those related records.
        foreach ($models as $model) {
            if (! is_null($value = $this->getForeignKeyFrom($model))) {
                $keys[] = $value;
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    public function match(array $models, EloquentCollection $results, string $relation): array
    {
        // First we will get to build a dictionary of the child models by their primary
        // key of the relationship, then we can easily match the children back onto
        // the parents using that dictionary and the primary key of the children.
        $dictionary = [];

        foreach ($results as $result) {
            $attribute = $this->getDictionaryKey($this->getRelatedKeyFrom($result));

            $dictionary[$attribute] = $result;
        }

        // Once we have the dictionary constructed, we can loop through all the parents
        // and match back onto their children using these keys of the dictionary and
        // the primary key of the children to map them onto the correct instances.
        foreach ($models as $model) {
            $attribute = $this->getDictionaryKey($this->getForeignKeyFrom($model));

            if (isset($dictionary[$attribute ?? ''])) {
                $model->setRelation($relation, $dictionary[$attribute ?? '']);
            }
        }

        return $models;
    }

    /**
     * Associate the model instance to the given parent.
     *
     * @param null|int|string|TRelatedModel $model
     * @return TDeclaringModel
     */
    public function associate(Model|int|string|null $model): Model
    {
        $ownerKey = $model instanceof Model ? $model->getAttribute($this->ownerKey) : $model;

        $this->child->setAttribute($this->foreignKey, $ownerKey);

        if ($model instanceof Model) {
            $this->child->setRelation($this->relationName, $model);
        } else {
            $this->child->unsetRelation($this->relationName);
        }

        return $this->child;
    }

    /**
     * Dissociate previously associated model from the given parent.
     *
     * @return TDeclaringModel
     */
    public function dissociate(): Model
    {
        $this->child->setAttribute($this->foreignKey, null);

        return $this->child->setRelation($this->relationName, null);
    }

    /**
     * Alias of "dissociate" method.
     *
     * @return TDeclaringModel
     */
    public function disassociate(): Model
    {
        return $this->dissociate();
    }

    /**
     * Touch all of the related models for the relationship.
     */
    public function touch(): void
    {
        if (! is_null($this->getParentKey())) {
            parent::touch();
        }
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from == $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return $query->select($columns)->whereColumn(
            $this->getQualifiedForeignKeyName(),
            '=',
            $query->qualifyColumn($this->ownerKey)
        );
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param \Hypervel\Database\Eloquent\Builder<TDeclaringModel> $parentQuery
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->select($columns)->from(
            $query->getModel()->getTable() . ' as ' . $hash = $this->getRelationCountHash()
        );

        $query->getModel()->setTable($hash);

        return $query->whereColumn(
            $hash . '.' . $this->ownerKey,
            '=',
            $this->getQualifiedForeignKeyName()
        );
    }

    /**
     * Determine if the related model has an auto-incrementing ID.
     */
    protected function relationHasIncrementingId(): bool
    {
        return $this->related->getIncrementing()
            && in_array($this->related->getKeyType(), ['int', 'integer']);
    }

    /**
     * Make a new related instance for the given model.
     *
     * @param TDeclaringModel $parent
     * @return TRelatedModel
     */
    protected function newRelatedInstanceFor(Model $parent): Model
    {
        return $this->related->newInstance();
    }

    /**
     * Get the child of the relationship.
     *
     * @return TDeclaringModel
     */
    public function getChild(): Model
    {
        return $this->child;
    }

    /**
     * Get the foreign key of the relationship.
     */
    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the fully qualified foreign key of the relationship.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->child->qualifyColumn($this->foreignKey);
    }

    /**
     * Get the key value of the child's foreign key.
     */
    public function getParentKey(): mixed
    {
        return $this->getForeignKeyFrom($this->child);
    }

    /**
     * Get the associated key of the relationship.
     */
    public function getOwnerKeyName(): ?string
    {
        return $this->ownerKey;
    }

    /**
     * Get the fully qualified associated key of the relationship.
     */
    public function getQualifiedOwnerKeyName(): string
    {
        return $this->related->qualifyColumn($this->ownerKey);
    }

    /**
     * Get the value of the model's foreign key.
     *
     * @param TRelatedModel $model
     */
    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->{$this->ownerKey};
    }

    /**
     * Get the value of the model's foreign key.
     *
     * @param TDeclaringModel $model
     */
    protected function getForeignKeyFrom(Model $model): mixed
    {
        $foreignKey = $model->{$this->foreignKey};

        return enum_value($foreignKey);
    }

    /**
     * Get the name of the relationship.
     */
    public function getRelationName(): string
    {
        return $this->relationName;
    }
}
