<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\Fixtures;

use Hypervel\Foundation\Auth\User;
use Hypervel\Notifications\Notifiable;

class AuthTestUser extends User
{
    use Notifiable;

    protected ?string $table = 'users';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected array $guarded = [];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected array $hidden = [
        'password',
        'remember_token',
    ];
}
