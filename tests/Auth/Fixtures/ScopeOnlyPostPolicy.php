<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

use Hypervel\Database\Eloquent\Builder;
use stdClass;

class ScopeOnlyPostPolicy
{
    /**
     * Determine if the user can edit the post.
     */
    public function edit(stdClass $user, ScopablePost $post): bool
    {
        return $user->id === $post->author_id;
    }

    /**
     * Filter a query to only posts the user can edit.
     */
    public function editScope(stdClass $user, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('author_id'), $user->id);
    }
}
