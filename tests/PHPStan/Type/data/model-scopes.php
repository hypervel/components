<?php

namespace ModelScope;

use App\User;
use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

/**
 * @param Builder<Model&ForTimeline> $intersection
 * @param Builder<Model&WithAttributedScope> $attributed
 */
function test(Builder $intersection, Builder $attributed): void
{
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model&ModelScope\ForTimeline>', $intersection->where('foo', 'bar'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model&ModelScope\ForTimeline>', $intersection->forTimeline());
    assertType('Hypervel\Database\Eloquent\Model&ModelScope\ForTimeline', $intersection->forTimeline()->firstOrFail());

    // An implementation does not inherit the interface method's attribute, so it is no scope.
    assertType('*ERROR*', $attributed->archived());
}

class Scopes extends Model
{
    private User $user;

    public function scopeWithVoidReturn(Builder $query): void
    {
        $query->where('whyuse', 'void');
    }

    public function scopeCount(Builder $query): void
    {
        $query->whereNotNull('id');
    }

    /** @param Builder<Scopes> $query */
    public function test(User $user, Builder $query): void
    {
        assertType('Hypervel\Database\Eloquent\Relations\HasOne<App\User, $this(ModelScope\Scopes)>', $this->hasOne(User::class)->active());
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\User, $this(ModelScope\Scopes)>', $this->hasMany(User::class)->active());
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->active());

        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $this->user->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->where('name', 'bar')->active());
        assertType('App\User|null', $this->user->where('name', 'bar')->active()->first());
        assertType('Hypervel\Database\Eloquent\Builder<ModelScope\Scopes>', $this->withVoidReturn());
        assertType('ModelScope\Scopes|null', $this->withVoidReturn()->first());
        // Unlike upstream, self:: keeps the called model, as PHP forwards it.
        assertType('Hypervel\Database\Eloquent\Builder<static(ModelScope\Scopes)>', self::query()->count());
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->whereActive());

        // Unlike upstream, User's @method scopeSomeScope tag creates no scope: Model::hasNamedScope() needs a real method.
        assertType('*ERROR*', $user->someScope());
        assertType('*ERROR*', User::query()->someScope());
        assertType('*ERROR*', $user->where('foo')->someScope());
    }
}

interface ForTimeline
{
    /**
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public function scopeForTimeline(Builder $query): Builder;
}

interface WithAttributedScope
{
    /** @param Builder<Model> $query */
    #[Scope]
    public function archived(Builder $query): void;
}

/** @param Builder<Activity> $query */
function attributeScopes(Builder $query): void
{
    assertType('Hypervel\Database\Eloquent\Builder<ModelScope\Activity>', Activity::completed());
    assertType('Hypervel\Database\Eloquent\Builder<ModelScope\Activity>', $query->completed());
    assertType('ModelScope\Activity', $query->completed(false)->firstOrFail());
    assertType('Hypervel\Database\Eloquent\Builder<ModelScope\Activity>', Activity::withVoidReturn());
    assertType('Hypervel\Database\Eloquent\Builder<ModelScope\Activity>', $query->withVoidReturn());
    assertType('ModelScope\Activity', $query->withVoidReturn()->firstOrFail());
    // Unlike upstream, a public attributed scope runs as a scope through the builder, as Eloquent permits.
    assertType('Hypervel\Database\Eloquent\Builder<ModelScope\Activity>', $query->notAScope());
}

class Activity extends Model
{
    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    #[Scope]
    protected function completed(Builder $query, bool $completed = true): Builder
    {
        return $query->where('completed', $completed);
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function withVoidReturn(Builder $query): void
    {
        $query->where('whyuse', 'void');
    }

    /** @param Builder<static> $query */
    #[Scope]
    public function notAScope(Builder $query): void
    {
        $query->where('whyuse', 'void');
    }
}
