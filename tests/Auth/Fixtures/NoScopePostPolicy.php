<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

use stdClass;

class NoScopePostPolicy
{
    /**
     * Determine if the user can edit the post.
     */
    public function edit(stdClass $user, ScopablePost $post): bool
    {
        return $user->id === $post->author_id;
    }
}
