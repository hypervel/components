<?php

declare(strict_types=1);

namespace Hypervel\Permission\Scopes;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Scope;
use Hypervel\Permission\PermissionRegistrar;

class PermissionPartitionScope implements Scope
{
    /**
     * Apply the permission partition to a query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $partition = Container::getInstance()
            ->make(PermissionRegistrar::class)
            ->resolvePartition();

        if ($partition) {
            $builder->where(
                $builder->qualifyColumn($partition->column),
                $partition->value,
            );
        }
    }
}
