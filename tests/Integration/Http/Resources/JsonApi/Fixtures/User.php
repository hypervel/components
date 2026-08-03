<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Attributes\UseResource;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Testbench\Factories\UserFactory;

#[UseResource(UserResource::class)]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use HasFactory;

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function chaperonePosts(): HasMany
    {
        return $this->hasMany(Post::class)->chaperone('author');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot('role')
            ->withTimestamps()
            ->using(Membership::class)
            ->as('membership');
    }
}
