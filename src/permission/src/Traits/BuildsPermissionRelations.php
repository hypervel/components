<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Relations\PartitionedBelongsToMany;
use Hypervel\Permission\Relations\PartitionedMorphToMany;
use Hypervel\Permission\Scopes\PermissionPartitionScope;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Support\PermissionPartition;
use Hypervel\Permission\Support\PermissionRelationContext;

/**
 * Build Permission relations from one immutable partition and team snapshot.
 *
 * The captured context must constrain related and pivot queries for the
 * relation's lifetime, even if ambient context later changes.
 */
trait BuildsPermissionRelations
{
    /**
     * Build a partition-aware belongs-to-many relation.
     *
     * @param class-string<Model> $related
     */
    protected function permissionBelongsToMany(
        string $related,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $relationName,
        ?PermissionRelationContext $context = null,
    ): BelongsToMany {
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);
        $context ??= new PermissionRelationContext($registrar->resolvePartition(), false, null);
        $partition = $context->partition;
        $instance = $this->newRelatedInstance($related);

        $this->ensurePermissionRelationParentMatches($registrar, $partition);
        $query = $this->permissionRelationQuery($instance, $partition);

        $relation = new PartitionedBelongsToMany(
            $query,
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $this->getKeyName(),
            $instance->getKeyName(),
            $relationName,
            $registrar,
            $context,
        );

        return $this->applyPermissionPartitionToRelation($relation, $partition);
    }

    /**
     * Build a partition-aware morph-to-many relation.
     *
     * @param class-string<Model> $related
     */
    protected function permissionMorphToMany(
        string $related,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $relationName,
        bool $inverse = false,
        bool $teamScoped = false,
        ?PermissionRelationContext $context = null,
    ): MorphToMany {
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);
        $context ??= new PermissionRelationContext(
            $registrar->resolvePartition(),
            $teamScoped,
            $teamScoped ? $registrar->getPermissionsTeamId() : null,
        );
        $partition = $context->partition;
        $team = $context->team;
        $instance = $this->newRelatedInstance($related);

        $this->ensurePermissionRelationParentMatches($registrar, $partition);
        $query = $this->permissionRelationQuery($instance, $partition);

        $relation = new PartitionedMorphToMany(
            $query,
            $this,
            'model',
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $this->getKeyName(),
            $instance->getKeyName(),
            $relationName,
            $inverse,
            $registrar,
            $context,
        );

        $relation = $this->applyPermissionPartitionToRelation($relation, $partition);

        if ($teamScoped) {
            $teamColumn = Config::teamForeignKey();
            $relation->withPivot($teamColumn);

            if ($team === null) {
                $relation->wherePivotNull($teamColumn);
            } else {
                $relation->withPivotValue($teamColumn, $team);
            }
        }

        return $relation;
    }

    /**
     * Build the related query using the relation's captured partition.
     */
    protected function permissionRelationQuery(Model $related, ?PermissionPartition $partition): Builder
    {
        $query = $related->newQuery();

        if ($partition && ($related instanceof Role || $related instanceof Permission)) {
            $query->withoutGlobalScope(PermissionPartitionScope::class)
                ->where($related->qualifyColumn($partition->column), $partition->value);
        }

        return $query;
    }

    /**
     * Ensure a partition-bearing relation parent belongs to the captured partition.
     */
    protected function ensurePermissionRelationParentMatches(
        PermissionRegistrar $registrar,
        ?PermissionPartition $partition,
    ): void {
        if (! $partition) {
            return;
        }

        $attributes = $this->getAttributes();

        if ($this instanceof Role || $this instanceof Permission) {
            // Eager loading defines relations on an attribute-less, non-persisted prototype;
            // its query is already constrained by the captured partition and real parent keys.
            if ($this->exists || array_key_exists($partition->column, $attributes)) {
                $registrar->ensureModelMatchesPartition($this, $partition);
            }

            return;
        }

        // An explicit null marks a global subject; Role and Permission never have that escape hatch.
        if (array_key_exists($partition->column, $attributes)
            && $attributes[$partition->column] !== null) {
            $registrar->ensureModelMatchesPartition($this, $partition);
        }
    }

    /**
     * Apply the captured partition to pivot reads and writes.
     *
     * @template TRelation of BelongsToMany
     * @param TRelation $relation
     * @return TRelation
     */
    protected function applyPermissionPartitionToRelation(
        BelongsToMany $relation,
        ?PermissionPartition $partition,
    ): BelongsToMany {
        if ($partition) {
            $relation->withPivot($partition->column)
                ->withPivotValue($partition->column, $partition->value);
        }

        return $relation;
    }
}
