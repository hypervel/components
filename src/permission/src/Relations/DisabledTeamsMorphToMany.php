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
 * Its queries join the direct permissions pivot but never match a row. Every pivot
 * statement, such as attaching or detaching, is rejected so it can't change those rows.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
class DisabledTeamsMorphToMany extends MorphToMany
{
    /**
     * Reject a pivot statement, since teams are disabled.
     *
     * @throws TeamsNotEnabled
     */
    public function newPivotStatement(): QueryBuilder
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
}
