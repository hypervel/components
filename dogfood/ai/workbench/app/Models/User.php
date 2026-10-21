<?php

declare(strict_types=1);

namespace Workbench\App\Models;

// use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Database\Eloquent\Attributes\Fillable;
use Hypervel\Database\Eloquent\Attributes\Hidden;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
