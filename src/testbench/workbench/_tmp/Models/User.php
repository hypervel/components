<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Workbench\Database\Factories\UserFactory;

#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
    ];
}
