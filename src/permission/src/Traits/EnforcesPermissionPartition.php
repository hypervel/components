<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Exceptions\PermissionPartitionViolation;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Support\Collection;

trait EnforcesPermissionPartition
{
    protected PermissionRegistrar $permissionPartitionRegistrar;

    protected PermissionRelationContext $permissionRelationContext;

    /**
     * Initialize the permission partition relation state.
     */
    protected function initializePermissionPartitionRelation(
        PermissionRegistrar $registrar,
        PermissionRelationContext $context,
    ): void {
        $this->permissionPartitionRegistrar = $registrar;
        $this->permissionRelationContext = $context;
    }

    /**
     * Get the relation's captured permission context.
     */
    public function getPermissionRelationContext(): PermissionRelationContext
    {
        return $this->permissionRelationContext;
    }

    /**
     * Get the connection that owns permission pivots.
     */
    protected function getPivotConnection(): ConnectionInterface
    {
        return $this->permissionPartitionRegistrar->getPermissionConnection();
    }

    /**
     * Format an attachment record without allowing partition overrides.
     */
    protected function formatAttachRecord(
        int|string $key,
        mixed $value,
        array $attributes,
        bool $hasTimestamps,
    ): array {
        $this->ensureTeamIsSelectedForPivotMutation();
        $partition = $this->permissionRelationContext->partition;

        if (! $partition) {
            return parent::formatAttachRecord($key, $value, $attributes, $hasTimestamps);
        }

        [, $mergedAttributes] = $this->extractAttachIdAndAttributes($key, $value, $attributes);
        $this->ensurePivotMatchesPermissionPartition($mergedAttributes);

        return array_replace(
            parent::formatAttachRecord($key, $value, $attributes, $hasTimestamps),
            [$partition->column => $partition->value],
        );
    }

    /**
     * Update an existing pivot without allowing partition movement.
     */
    public function updateExistingPivot(mixed $id, array $attributes, bool $touch = true): int
    {
        $this->ensureTeamIsSelectedForPivotMutation();
        $partition = $this->permissionRelationContext->partition;

        if (! $partition) {
            return parent::updateExistingPivot($id, $attributes, $touch);
        }

        $this->ensurePivotMatchesPermissionPartition($attributes);
        unset($attributes[$partition->column]);

        return parent::updateExistingPivot($id, $attributes, $touch);
    }

    /**
     * Detach pivot records within the captured permission context.
     */
    public function detach(mixed $ids = null, bool $touch = true): int
    {
        $this->ensureTeamIsSelectedForPivotMutation();

        return parent::detach($ids, $touch);
    }

    /**
     * Get and mark lazily loaded relation results.
     */
    public function getResults(): Collection
    {
        $results = parent::getResults();

        if ($this->relationName !== null) {
            $this->permissionPartitionRegistrar->markLoadedRelation(
                $this->parent,
                $this->relationName,
                $results,
                $this->permissionRelationContext,
            );
        }

        return $results;
    }

    /**
     * Initialize and mark eager-loaded relation collections.
     *
     * @param array<int, Model> $models
     * @return array<int, Model>
     */
    public function initRelation(array $models, string $relation): array
    {
        $models = parent::initRelation($models, $relation);
        $this->markPermissionRelationCollections($models, $relation);

        return $models;
    }

    /**
     * Match and mark eager-loaded relation collections.
     *
     * @param array<int, Model> $models
     * @return array<int, Model>
     */
    public function match(array $models, EloquentCollection $results, string $relation): array
    {
        $models = parent::match($models, $results, $relation);
        $this->markPermissionRelationCollections($models, $relation);

        return $models;
    }

    /**
     * Reject conflicting caller-supplied partition attributes.
     */
    protected function ensurePivotMatchesPermissionPartition(array $attributes): void
    {
        $partition = $this->permissionRelationContext->partition;

        if ($partition
            && array_key_exists($partition->column, $attributes)
            && ! $partition->matches($attributes[$partition->column])) {
            throw PermissionPartitionViolation::forPivot(
                $partition,
                $attributes[$partition->column],
            );
        }
    }

    /**
     * Ensure a team-scoped pivot mutation has a selected team.
     */
    private function ensureTeamIsSelectedForPivotMutation(): void
    {
        $this->permissionPartitionRegistrar->ensureTeamIsSelectedForMutation(
            $this->permissionRelationContext,
        );
    }

    /**
     * Mark the loaded collection attached to each eager-loaded model.
     *
     * @param array<int, Model> $models
     */
    protected function markPermissionRelationCollections(array $models, string $relation): void
    {
        foreach ($models as $model) {
            $collection = $model->getRelation($relation);

            if ($collection instanceof Collection) {
                $this->permissionPartitionRegistrar->markLoadedRelation(
                    $model,
                    $relation,
                    $collection,
                    $this->permissionRelationContext,
                );
            }
        }
    }
}
