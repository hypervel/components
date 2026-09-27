<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Tests\Database\Fixtures\Models\Integration\User;
use Override;

class NamedScopeUser extends User
{
    /**
     * Get the attributes that should be cast.
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Filter users by email verification.
     */
    #[Scope]
    protected function verified(Builder $builder, bool $email = true): Builder
    {
        return $builder->when(
            $email === true,
            fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'),
            fn (Builder $query): Builder => $query->whereNull('email_verified_at'),
        );
    }

    /**
     * Filter users without returning the query builder.
     */
    #[Scope]
    protected function verifiedWithoutReturn(Builder $builder, bool $email = true): void
    {
        $this->verified($builder, $email);
    }

    /**
     * Filter users through a convention-based scope.
     */
    public function scopeVerifiedUser(Builder $builder, bool $email = true): Builder
    {
        return $builder->when(
            $email === true,
            fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'),
            fn (Builder $query): Builder => $query->whereNull('email_verified_at'),
        );
    }
}
