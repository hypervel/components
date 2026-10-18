<?php

declare(strict_types=1);

namespace Hypervel\Ai\Scopes;

use Hypervel\Ai\Support\ConversationPartition;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Scope;

class ConversationPartitionScope implements Scope
{
    /**
     * Apply the conversation partition to a query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $partition = ConversationPartition::current();

        if ($partition) {
            $builder->where(
                $builder->qualifyColumn($partition->column),
                $partition->value,
            );
        }
    }
}
