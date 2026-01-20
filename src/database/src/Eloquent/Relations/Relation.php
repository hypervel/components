<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Closure;
use Hyperf\Database\Model\Relations\Relation as BaseRelation;
use Hypervel\Database\Eloquent\Relations\Contracts\Relation as RelationContract;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TParentModel of \Hypervel\Database\Eloquent\Model
 * @template TResult
 *
 * @implements RelationContract<TRelatedModel, TParentModel, TResult>
 */
abstract class Relation extends BaseRelation implements RelationContract
{
    /**
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public static function noConstraints($callback)
    {
        return parent::noConstraints($callback);
    }
}
