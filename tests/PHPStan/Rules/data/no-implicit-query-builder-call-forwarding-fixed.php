<?php

namespace NoImplicitQueryBuilderCallForwarding;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;

class EnabledUser extends Model
{
    public function __call(string $method, array $parameters): mixed
    {
        $result = parent::__call($method, $parameters);

        return $method === 'where' ? $result->where('enabled', true) : $result;
    }
}

class QueriedUser extends Model
{
    /** @return Builder<static> */
    public static function query(): Builder
    {
        return parent::query()->where('enabled', true);
    }
}

class ScopedUser extends Model
{
    /** @return Builder<static> */
    public function newQuery(): Builder
    {
        return parent::newQuery()->where('enabled', true);
    }
}

function forwarding(EnabledUser $enabled, QueriedUser $queried, ScopedUser $scoped): void
{
    EnabledUser::where('id', 1);
    $enabled->where('id', 1);
    QueriedUser::where('id', 1);
    $queried->newQuery()->where('id', 1);
    ScopedUser::query()->where('id', 1);
    $scoped->newQuery()->where('id', 1);
}
