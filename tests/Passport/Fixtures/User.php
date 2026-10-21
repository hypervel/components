<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Fixtures;

use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Notifications\Notifiable;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected array $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected array $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'email_verified_at' => 'datetime',
    ];
}
