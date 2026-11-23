<?php

namespace BugFinalModelQuery;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;

final class User extends Model
{
    /** @return Builder<self> */
    public function test(): Builder
    {
        return self::query();
    }
}
