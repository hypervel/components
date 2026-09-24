<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use BadMethodCallException;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\HasOneOrMany;
use Hypervel\Database\Eloquent\Relations\HasOneThrough;
use Hypervel\Database\Eloquent\Relations\MorphOneOrMany;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;

/**
 * @template TIntermediateModel of Model
 * @template TDeclaringModel of Model
 * @template TLocalRelationship of HasOneOrMany<TIntermediateModel, TDeclaringModel>
 */
class PendingHasThroughRelationship
{
    /**
     * The root model that the relationship exists on.
     *
     * @var TDeclaringModel
     */
    protected Model $rootModel;

    /**
     * The local relationship.
     *
     * @var TLocalRelationship
     */
    protected HasOneOrMany $localRelationship;

    /**
     * Create a pending has-many-through or has-one-through relationship.
     *
     * @param TDeclaringModel $rootModel
     * @param TLocalRelationship $localRelationship
     */
    public function __construct(Model $rootModel, HasOneOrMany $localRelationship)
    {
        $this->rootModel = $rootModel;
        $this->localRelationship = $localRelationship;
    }

    /**
     * Define the distant relationship that this model has.
     *
     * @template TRelatedModel of Model
     *
     * @param (callable(TIntermediateModel): (HasMany<TRelatedModel, TIntermediateModel>|HasOne<TRelatedModel, TIntermediateModel>|MorphOneOrMany<TRelatedModel, TIntermediateModel>))|string $callback
     * @return (
     *     $callback is string
     *     ? HasManyThrough<Model, TIntermediateModel, TDeclaringModel>|HasOneThrough<Model, TIntermediateModel, TDeclaringModel>
     *     : (
     *         TLocalRelationship is HasMany<TIntermediateModel, TDeclaringModel>
     *         ? HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     *         : (
     *              $callback is callable(TIntermediateModel): HasMany<TRelatedModel, TIntermediateModel>
     *              ? HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     *              : HasOneThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     *         )
     *     )
     * )
     */
    public function has(callable|string $callback): mixed
    {
        if (is_string($callback)) {
            $callback = fn () => $this->localRelationship->getRelated()->{$callback}();
        }

        $distantRelation = $callback($this->localRelationship->getRelated());

        if ($distantRelation instanceof HasMany || $this->localRelationship instanceof HasMany) {
            $returnedRelation = $this->rootModel->hasManyThrough(
                $distantRelation->getRelated()::class,
                $this->localRelationship->getRelated()::class,
                $this->localRelationship->getForeignKeyName(),
                $distantRelation->getForeignKeyName(),
                $this->localRelationship->getLocalKeyName(),
                $distantRelation->getLocalKeyName(),
            );
        } else {
            $returnedRelation = $this->rootModel->hasOneThrough(
                $distantRelation->getRelated()::class,
                $this->localRelationship->getRelated()::class,
                $this->localRelationship->getForeignKeyName(),
                $distantRelation->getForeignKeyName(),
                $this->localRelationship->getLocalKeyName(),
                $distantRelation->getLocalKeyName(),
            );
        }

        if ($this->localRelationship instanceof MorphOneOrMany) {
            $returnedRelation->where($this->localRelationship->getQualifiedMorphType(), $this->localRelationship->getMorphClass());
        }

        return $returnedRelation;
    }

    /**
     * Handle dynamic method calls into the model.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (Str::startsWith($method, 'has')) {
            return $this->has((new Stringable($method))->after('has')->lcfirst()->toString());
        }

        throw new BadMethodCallException(sprintf(
            'Call to undefined method %s::%s()',
            static::class,
            $method
        ));
    }
}
