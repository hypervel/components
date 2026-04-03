<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Support\Facades\DB;
use stdClass;

class ScopablePostPolicyWithBefore
{
    /**
     * Run before all checks — admin bypasses everything.
     */
    public function before(stdClass $user, string $ability): ?bool
    {
        if ($user->is_admin) {
            return true;
        }

        return null;
    }

    /**
     * Filter a query to only posts the user can edit.
     */
    public function editScope(stdClass $user, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('author_id'), $user->id);
    }

    /**
     * Return a SQL expression for per-row edit authorization.
     */
    public function editSelect(stdClass $user, Builder $query): Expression
    {
        return DB::raw($query->qualifyColumn('author_id') . ' = ' . (int) $user->id);
    }
}
