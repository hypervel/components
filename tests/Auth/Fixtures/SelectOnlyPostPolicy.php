<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Support\Facades\DB;
use stdClass;

class SelectOnlyPostPolicy
{
    /**
     * Determine if the user can edit the post.
     */
    public function edit(stdClass $user, ScopablePost $post): bool
    {
        return $user->id === $post->author_id;
    }

    /**
     * Return a SQL expression for per-row edit authorization.
     */
    public function editSelect(stdClass $user, Builder $query): Expression
    {
        return DB::raw($query->qualifyColumn('author_id') . ' = ' . (int) $user->id);
    }
}
