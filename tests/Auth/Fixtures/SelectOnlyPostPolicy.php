<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Query\Builder as QueryBuilder;
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
     * Return a scalar boolean selection for per-row edit authorization.
     */
    public function editSelect(stdClass $user, Builder $query): QueryBuilder
    {
        return $query->getQuery()->newQuery()->selectRaw(
            $query->qualifyColumn('author_id') . ' = ?',
            [$user->id],
        );
    }
}
