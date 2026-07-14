<?php

declare(strict_types=1);

namespace Hypervel\Permission\Relations;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Permission\Traits\EnforcesPermissionPartition;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
class PartitionedMorphToMany extends MorphToMany
{
    use EnforcesPermissionPartition;

    /**
     * Create a partition-aware morph-to-many relation.
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $name,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName,
        bool $inverse,
        PermissionRegistrar $registrar,
        PermissionRelationContext $context,
    ) {
        $this->initializePermissionPartitionRelation($registrar, $context);

        parent::__construct(
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
}
