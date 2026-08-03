<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Query\Builder;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Support\PermissionPartition;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

trait HasAssignedModels
{
    use BuildsPermissionRelations;

    /**
     * Assign this role to the given models without removing existing assignments.
     *
     * @param array<int, int|Model|string>|Collection<int, int|Model|string>|int|Model|string $models
     * @return $this
     */
    public function assignToModels(array|Collection|Model|int|string $models, ?string $modelClass = null): static
    {
        if (! $this->exists) {
            return $this;
        }

        $registrar = Container::getInstance()->make(PermissionRegistrar::class);
        $context = $this->assignedModelRelationContext($registrar);
        $groupedModels = $this->groupModelsByMorphClass($models, $modelClass, $registrar, $context->partition);

        if ($groupedModels !== []) {
            $assignGroups = function () use ($context, $groupedModels): void {
                foreach ($groupedModels as $groupedModelClass => $ids) {
                    $relation = $this->relationForModel($groupedModelClass, $context);
                    $existingIds = $relation->newPivotQuery()
                        ->whereIn(Config::morphKey(), $ids)
                        ->pluck(Config::morphKey())
                        ->all();
                    $missingIds = array_diff($ids, $existingIds);

                    if ($missingIds !== []) {
                        $relation->attach($missingIds);
                    }
                }
            };

            if (count($groupedModels) === 1) {
                $assignGroups();
            } else {
                $this->getConnection()->transaction($assignGroups);
            }
        }

        foreach ($groupedModels as $groupedModelClass => $ids) {
            $this->forgetAssignedModelCaches($registrar, $groupedModelClass, $ids, $context);
        }

        $this->unsetRelation('users');

        return $this;
    }

    /**
     * Remove this role from the given models.
     *
     * @param array<int, int|Model|string>|Collection<int, int|Model|string>|int|Model|string $models
     * @return $this
     */
    public function removeFromModels(array|Collection|Model|int|string $models, ?string $modelClass = null): static
    {
        if (! $this->exists) {
            return $this;
        }

        $registrar = Container::getInstance()->make(PermissionRegistrar::class);
        $context = $this->assignedModelRelationContext($registrar);
        $groupedModels = $this->groupModelsByMorphClass($models, $modelClass, $registrar, $context->partition);

        if ($groupedModels !== []) {
            $removeGroups = function () use ($context, $groupedModels): void {
                foreach ($groupedModels as $groupedModelClass => $ids) {
                    $this->relationForModel($groupedModelClass, $context)->detach($ids);
                }
            };

            if (count($groupedModels) === 1) {
                $removeGroups();
            } else {
                $this->getConnection()->transaction($removeGroups);
            }
        }

        foreach ($groupedModels as $groupedModelClass => $ids) {
            $this->forgetAssignedModelCaches($registrar, $groupedModelClass, $ids, $context);
        }

        $this->unsetRelation('users');

        return $this;
    }

    /**
     * Remove all current model associations and set the given ones.
     *
     * @param array<int, int|Model|string>|Collection<int, int|Model|string>|int|Model|string $models
     * @return $this
     */
    public function syncModels(array|Collection|Model|int|string $models, ?string $modelClass = null): static
    {
        if (! $this->exists) {
            return $this;
        }

        $registrar = Container::getInstance()->make(PermissionRegistrar::class);
        $context = $this->assignedModelRelationContext($registrar);
        $groupedModels = $this->groupModelsByMorphClass($models, $modelClass, $registrar, $context->partition);

        $this->getConnection()->transaction(function () use ($context, $groupedModels): void {
            $this->newPivotQueryForRole($context)->delete();

            foreach ($groupedModels as $groupedModelClass => $ids) {
                $this->relationForModel($groupedModelClass, $context)->attach($ids);
            }
        });

        $this->unsetRelation('users');
        $registrar->bumpModelAssignmentCacheTokenFor($context->partition);

        return $this;
    }

    /**
     * Build a morphedByMany relation pointing to a specific model class.
     */
    protected function relationForModel(
        string $modelClass,
        ?PermissionRelationContext $context = null,
    ): MorphToMany {
        return $this->permissionMorphToMany(
            $modelClass,
            Config::modelHasRolesTable(),
            Container::getInstance()->make(PermissionRegistrar::class)->pivotRole,
            Config::morphKey(),
            'users',
            inverse: true,
            teamScoped: Config::teamsEnabled(),
            context: $context,
        );
    }

    /**
     * Group the given models by class, deduplicating IDs within each class.
     *
     * @param array<int, int|Model|string>|Collection<int, int|Model|string>|int|Model|string $models
     * @return array<class-string<Model>, list<int|string>>
     */
    private function groupModelsByMorphClass(
        array|Collection|Model|int|string $models,
        ?string $modelClass,
        PermissionRegistrar $registrar,
        ?PermissionPartition $partition,
    ): array {
        $defaultModelClass = $this->resolveDefaultModelClass($modelClass);

        return collect(Arr::flatten(Arr::wrap($models)))
            ->reject(fn ($value) => $value === null || $value === '')
            ->reduce(function (array $grouped, $value) use ($defaultModelClass, $registrar, $partition) {
                if ($value instanceof Model && $partition) {
                    $attributes = $value->getAttributes();

                    if (array_key_exists($partition->column, $attributes)
                        && $attributes[$partition->column] !== null) {
                        $registrar->ensureModelMatchesPartition($value, $partition);
                    }
                }

                $class = $value instanceof Model ? $value::class : $defaultModelClass;
                $id = $value instanceof Model ? $this->requireAssignedModelKey($value) : $value;

                if (! in_array($id, $grouped[$class] ?? [], strict: true)) {
                    $grouped[$class][] = $id;
                }

                return $grouped;
            }, []);
    }

    /**
     * Get a required key for a reverse-assignment model.
     */
    private function requireAssignedModelKey(Model $model): mixed
    {
        $key = $model->getKey();

        if ($key === null) {
            throw new MissingAttributeException($model, $model->getKeyName());
        }

        return $key;
    }

    /**
     * Resolve the model class to use when raw ids are passed.
     *
     * @return class-string<Model>
     */
    private function resolveDefaultModelClass(?string $modelClass): string
    {
        return $modelClass
            ?? Config::defaultModel()
            ?? getModelForGuard($this->attributes['guard_name'] ?? Config::defaultGuard());
    }

    /**
     * Validate the owning role and capture its reverse assignment context.
     */
    private function assignedModelRelationContext(PermissionRegistrar $registrar): PermissionRelationContext
    {
        $this->requireAssignedModelKey($this);

        $partition = $registrar->resolvePartition();

        if ($partition) {
            $registrar->ensureModelMatchesPartition($this, $partition);
        }

        $teamScoped = Config::teamsEnabled();

        return new PermissionRelationContext(
            $partition,
            $teamScoped,
            $teamScoped ? $registrar->getPermissionsTeamId() : null,
        );
    }

    /**
     * Forget exact assignment caches affected by a reverse assignment operation.
     *
     * @param class-string<Model> $modelClass
     * @param list<int|string> $ids
     */
    private function forgetAssignedModelCaches(
        PermissionRegistrar $registrar,
        string $modelClass,
        array $ids,
        PermissionRelationContext $context,
    ): void {
        $morphType = (new $modelClass)->getMorphClass();

        foreach ($ids as $id) {
            $registrar->forgetModelAssignmentCacheForIdentity(
                $morphType,
                $id,
                $context->partition,
                $context->team,
            );
        }
    }

    /**
     * Build the raw pivot query used to replace this role's assignments.
     */
    private function newPivotQueryForRole(PermissionRelationContext $context): Builder
    {
        $query = $this->getConnection()
            ->table(Config::modelHasRolesTable())
            ->where(Container::getInstance()->make(PermissionRegistrar::class)->pivotRole, $this->getKey());

        if ($context->partition) {
            $query->where($context->partition->column, $context->partition->value);
        }

        if ($context->teamScoped) {
            $query->where(Config::teamForeignKey(), $context->team);
        }

        return $query;
    }
}
