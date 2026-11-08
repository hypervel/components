<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

use Hypervel\Database\Eloquent\HasBuilder;

class User extends BaseModel
{
    /** @use HasBuilder<UserBuilder<static>> */
    use HasBuilder;

    protected static string $builder = UserBuilder::class;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'name' => 'string',
        ];
    }
}
