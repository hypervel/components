<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Models;

use CarbonImmutable;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property null|string $remember_token
 * @property null|CarbonImmutable $email_verified_at
 * @property null|CarbonImmutable $created_at
 * @property null|CarbonImmutable $updated_at
 */
class User extends Authenticatable
{
    use HasFactory;
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
        'password' => 'hashed',
    ];
}
