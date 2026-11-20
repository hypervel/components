<?php

declare(strict_types=1);

namespace Hypervel\Permission\Relations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Permission\Exceptions\TeamsNotEnabled;
use Hypervel\Support\Collection;

/**
 * The teams relation while the teams feature is disabled.
 *
 * Its queries join the direct permissions pivot but never match a row. Every write, such as
 * attaching, detaching or creating a related model, is rejected before it changes any rows.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
class DisabledTeamsMorphToMany extends MorphToMany
{
    /**
     * Reject attaching models, since teams are disabled.
     *
     * @throws TeamsNotEnabled
     */
    public function attach(mixed $ids, array $attributes = [], bool $touch = true): void
    {
        throw TeamsNotEnabled::create();
    }

    /**
     * Reject querying the pivot table, since teams are disabled.
     *
     * Syncing, toggling, detaching and updating pivot records all start here, so this
     * rejects them even when they would find nothing to change.
     *
     * @throws TeamsNotEnabled
     */
    public function newPivotQuery(): QueryBuilder
    {
        throw TeamsNotEnabled::create();
    }

    /**
     * Get all of the IDs for the related models, of which there are none.
     *
     * @return Collection<int, int|string>
     */
    public function allRelatedIds(): Collection
    {
        return new Collection;
    }

    /**
     * Reject saving a related model, since teams are disabled.
     *
     * @throws TeamsNotEnabled
     */
    public function save(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        throw TeamsNotEnabled::create();
    }

    /**
     * Reject creating a related model, since teams are disabled.
     *
     * @throws TeamsNotEnabled
     */
    public function create(array $attributes = [], array $joining = [], bool $touch = true): Model
    {
        throw TeamsNotEnabled::create();
    }

    /**
     * Reject force creating a related model, since teams are disabled.
     *
     * @throws TeamsNotEnabled
     */
    public function forceCreate(array $attributes = [], array $joining = [], bool $touch = true): Model
    {
        throw TeamsNotEnabled::create();
    }
}
