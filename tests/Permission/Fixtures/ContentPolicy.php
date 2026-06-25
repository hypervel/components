<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures;

use Hypervel\Tests\Permission\Fixtures\Models\Content;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class ContentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('testAdminRole', 'admin') ?: null;
    }

    public function view(User $user, Content $content): bool
    {
        return $user->id === $content->user_id;
    }

    public function update(User $user, Content $modelRecord): bool
    {
        return $user->id === $modelRecord->user_id || $user->can('edit-articles');
    }
}
