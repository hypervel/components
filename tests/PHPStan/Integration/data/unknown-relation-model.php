<?php

namespace UnknownRelationModel;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Relations\Relation;

/**
 * @param Relation<*, *, *> $relation
 * @param Builder<\App\User> $query
 */
function existence(Relation $relation, Builder $query): void
{
    $relation->getRelationExistenceCountQuery($query, $query);
}
