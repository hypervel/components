<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

class Usage
{
    /**
     * Read a column the migration does not create.
     */
    public function nickname(User $user): mixed
    {
        return $user->nickname;
    }

    /**
     * Read a column the model casts.
     */
    public function name(User $user): string
    {
        return $user->name;
    }
}
