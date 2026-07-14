<?php

declare(strict_types=1);

namespace Hypervel\Permission\Relations;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Permission\Traits\EnforcesPermissionPartition;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel>
 */
class PartitionedBelongsToMany extends BelongsToMany
{
    use EnforcesPermissionPartition;

    /**
     * Create a partition-aware belongs-to-many relation.
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @param class-string<TRelatedModel>|string $table
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName,
        PermissionRegistrar $registrar,
        PermissionRelationContext $context,
    ) {
        $this->initializePermissionPartitionRelation($registrar, $context);

        parent::__construct(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
        );
    }
}
