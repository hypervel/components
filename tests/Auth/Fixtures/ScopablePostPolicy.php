<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Support\Facades\DB;
use stdClass;

class ScopablePostPolicy
{
    /**
     * Determine if the user can edit the post.
     */
    public function edit(stdClass $user, ScopablePost $post): bool
    {
        return $user->is_admin || $post->author_id === $user->id;
    }

    /**
     * Filter a query to only posts the user can edit.
     */
    public function editScope(stdClass $user, Builder $query): Builder
    {
        if ($user->is_admin) {
            return $query;
        }

        return $query->where($query->qualifyColumn('author_id'), $user->id);
    }

    /**
     * Return a SQL expression for per-row edit authorization.
     */
    public function editSelect(stdClass $user, Builder $query): Expression
    {
        if ($user->is_admin) {
            return DB::raw('true');
        }

        return DB::raw($query->qualifyColumn('author_id') . ' = ' . (int) $user->id);
    }
}
