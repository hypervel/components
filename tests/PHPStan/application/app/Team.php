<?php

declare(strict_types=1);

namespace App;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder;

/**
 * @property string $name
 */
class Team extends Model
{
    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected string $keyType = 'string';

    /**
     * @return ChildTeamBuilder
     */
    public static function query(): ChildTeamBuilder
    {
        return parent::query();
    }

    /**
     * @return ChildTeamBuilder
     */
    public function newEloquentBuilder(Builder $query): ChildTeamBuilder
    {
        return new ChildTeamBuilder($query);
    }
}
