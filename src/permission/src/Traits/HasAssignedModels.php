<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Query\Builder;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

trait HasAssignedModels
{
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
        $teamPivot = $this->teamPivot();

        foreach ($this->groupModelsByMorphClass($models, $modelClass) as $morphClass => $ids) {
            $relation = $this->relationForModel($morphClass);
            $existingIds = $relation->pluck(Config::morphKey())->all();

            $relation->attach(array_diff($ids, $existingIds), $teamPivot);
        }

        $this->unsetRelation('users');
        $registrar->bumpModelAssignmentCacheToken();

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
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);

        foreach ($this->groupModelsByMorphClass($models, $modelClass) as $morphClass => $ids) {
            $this->relationForModel($morphClass)->detach($ids);
        }

        $this->unsetRelation('users');
        $registrar->bumpModelAssignmentCacheToken();

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
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);

        if ($this->exists) {
            $this->newPivotQueryForRole()->delete();
        }

        $teamPivot = $this->teamPivot();

        foreach ($this->groupModelsByMorphClass($models, $modelClass) as $morphClass => $ids) {
            $this->relationForModel($morphClass)->attach($ids, $teamPivot);
        }

        $this->unsetRelation('users');
        $registrar->bumpModelAssignmentCacheToken();

        return $this;
    }

    /**
     * Build a morphedByMany relation pointing to a specific model class.
     */
    protected function relationForModel(string $modelClass): MorphToMany
    {
        $relation = $this->morphedByMany(
            $modelClass,
            'model',
            Config::modelHasRolesTable(),
            Container::getInstance()->make(PermissionRegistrar::class)->pivotRole,
            Config::morphKey(),
        );

        if (! Config::teamsEnabled()) {
            return $relation;
        }

        return $relation->wherePivot(Config::teamForeignKey(), getPermissionsTeamId());
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
    ): array {
        $defaultModelClass = $this->resolveDefaultModelClass($modelClass);

        return collect(Arr::flatten(Arr::wrap($models)))
            ->reject(fn ($value) => $value === null || $value === '')
            ->reduce(function (array $grouped, $value) use ($defaultModelClass) {
                $class = $value instanceof Model ? $value::class : $defaultModelClass;
                $id = $value instanceof Model ? $value->getKey() : $value;

                if (! in_array($id, $grouped[$class] ?? [], strict: true)) {
                    $grouped[$class][] = $id;
                }

                return $grouped;
            }, []);
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
     * @return array<string, null|int|string>
     */
    private function teamPivot(): array
    {
        if (! Config::teamsEnabled()) {
            return [];
        }

        return [Config::teamForeignKey() => getPermissionsTeamId()];
    }

    private function newPivotQueryForRole(): Builder
    {
        $query = $this->getConnection()
            ->table(Config::modelHasRolesTable())
            ->where(Container::getInstance()->make(PermissionRegistrar::class)->pivotRole, $this->getKey());

        if (Config::teamsEnabled()) {
            $query->where(Config::teamForeignKey(), getPermissionsTeamId());
        }

        return $query;
    }
}
