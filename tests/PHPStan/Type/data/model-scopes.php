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
        assertType('Hypervel\Database\Eloquent\Builder<ModelScope\Scopes>', self::query()->count());
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
