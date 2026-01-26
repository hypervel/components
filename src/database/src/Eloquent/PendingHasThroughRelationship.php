<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use BadMethodCallException;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOneOrMany;
use Hypervel\Database\Eloquent\Relations\MorphOneOrMany;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;

/**
 * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 * @template TLocalRelationship of \Hypervel\Database\Eloquent\Relations\HasOneOrMany<TIntermediateModel, TDeclaringModel>
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
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param (callable(TIntermediateModel): (\Hypervel\Database\Eloquent\Relations\HasMany<TRelatedModel, TIntermediateModel>|\Hypervel\Database\Eloquent\Relations\HasOne<TRelatedModel, TIntermediateModel>|\Hypervel\Database\Eloquent\Relations\MorphOneOrMany<TRelatedModel, TIntermediateModel>))|string $callback
     * @return (
     *     $callback is string
     *     ? \Hypervel\Database\Eloquent\Relations\HasManyThrough<\Hypervel\Database\Eloquent\Model, TIntermediateModel, TDeclaringModel>|\Hypervel\Database\Eloquent\Relations\HasOneThrough<\Hypervel\Database\Eloquent\Model, TIntermediateModel, TDeclaringModel>
     *     : (
     *         TLocalRelationship is \Hypervel\Database\Eloquent\Relations\HasMany<TIntermediateModel, TDeclaringModel>
     *         ? \Hypervel\Database\Eloquent\Relations\HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     *         : (
     *              $callback is callable(TIntermediateModel): \Hypervel\Database\Eloquent\Relations\HasMany<TRelatedModel, TIntermediateModel>
     *              ? \Hypervel\Database\Eloquent\Relations\HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     *              : \Hypervel\Database\Eloquent\Relations\HasOneThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
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
