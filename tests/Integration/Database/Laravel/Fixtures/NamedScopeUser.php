<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Fixtures;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Override;

class NamedScopeUser extends User
{
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    #[Scope]
    protected function verified(Builder $builder, bool $email = true)
    {
        return $builder->when(
            $email === true,
            fn ($query) => $query->whereNotNull('email_verified_at'),
            fn ($query) => $query->whereNull('email_verified_at'),
        );
    }

    #[Scope]
    protected function verifiedWithoutReturn(Builder $builder, bool $email = true)
    {
        $this->verified($builder, $email);
    }

    public function scopeVerifiedUser(Builder $builder, bool $email = true)
    {
        return $builder->when(
            $email === true,
            fn ($query) => $query->whereNotNull('email_verified_at'),
            fn ($query) => $query->whereNull('email_verified_at'),
        );
    }
}
