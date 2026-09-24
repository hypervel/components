<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\Fixtures\Models\Nested;

use Hypervel\Foundation\Auth\User as Authenticatable;

class SubTestUser extends Authenticatable
{
    protected ?string $table = 'users';

    public bool $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var string[]
     */
    protected array $guarded = [];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var string[]
     */
    protected array $hidden = [
        'password', 'remember_token',
    ];
}
