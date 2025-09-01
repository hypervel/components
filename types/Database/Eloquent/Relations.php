<?php

declare(strict_types=1);

namespace Hypervel\Types\Relations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\HasOneThrough;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Eloquent\Relations\Relation;

use function PHPStan\Testing\assertType;

function test(User $user, Post $post, Comment $comment, ChildUser $child): void
{
    assertType('Hypervel\Database\Eloquent\Relations\HasOne<Hypervel\Types\Relations\Address, Hypervel\Types\Relations\User>', $user->address());
    assertType('Hypervel\Types\Relations\Address|null', $user->address()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Address>', $user->address()->get());
    assertType('Hypervel\Types\Relations\Address', $user->address()->make());
    assertType('Hypervel\Types\Relations\Address', $user->address()->create());
    assertType('Hypervel\Database\Eloquent\Relations\HasOne<Hypervel\Types\Relations\Address, Hypervel\Types\Relations\ChildUser>', $child->address());
    assertType('Hypervel\Types\Relations\Address', $child->address()->make());
    assertType('Hypervel\Types\Relations\Address', $child->address()->create([]));
    assertType('Hypervel\Types\Relations\Address', $child->address()->getRelated());
    assertType('Hypervel\Types\Relations\ChildUser', $child->address()->getParent());

    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\Relations\Post, Hypervel\Types\Relations\User>', $user->posts());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Post>', $user->posts()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Post>', $user->posts()->createMany([]));
    assertType('Hypervel\Types\Relations\Post', $user->posts()->make());
    assertType('Hypervel\Types\Relations\Post', $user->posts()->create());
    assertType('Hypervel\Types\Relations\Post|false', $user->posts()->save(new Post()));

    assertType("Hypervel\\Database\\Eloquent\\Relations\\BelongsToMany<Hypervel\\Types\\Relations\\Role, Hypervel\\Types\\Relations\\User, Hypervel\\Database\\Eloquent\\Relations\\Pivot, 'pivot'>", $user->roles());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->find([1]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findMany([1, 2, 3]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findOrNew([1]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findOrFail([1]));
    assertType('Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->findOrNew(1));
    assertType('Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->findOrFail(1));
    assertType('(Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $user->roles()->find(1));
    assertType('(Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $user->roles()->first());
    assertType('Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->firstOrNew());
    assertType('Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->firstOrFail());
    assertType('Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->firstOrCreate());
    assertType('Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->create());
    assertType('Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->updateOrCreate([]));
    assertType('Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->save(new Role()));
    $roles = $user->roles()->getResults();
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->saveMany($roles));
    assertType('array<int, Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->saveMany($roles->all()));
    assertType('array<int, Hypervel\Types\Relations\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->createMany($roles->all()));
    assertType('array{attached: array, detached: array, updated: array}', $user->roles()->sync($roles));
    assertType('array{attached: array, detached: array, updated: array}', $user->roles()->syncWithoutDetaching($roles));

    assertType('Hypervel\Database\Eloquent\Relations\HasOneThrough<Hypervel\Types\Relations\Car, Hypervel\Types\Relations\Mechanic, Hypervel\Types\Relations\User>', $user->car());
    assertType('Hypervel\Types\Relations\Car|null', $user->car()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Car>', $user->car()->find([1]));
    assertType('Hypervel\Types\Relations\Car|null', $user->car()->find(1));
    assertType('Hypervel\Types\Relations\Car|null', $user->car()->first());

    assertType('Hypervel\Database\Eloquent\Relations\HasManyThrough<Hypervel\Types\Relations\Part, Hypervel\Types\Relations\Mechanic, Hypervel\Types\Relations\User>', $user->parts());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Part>', $user->parts()->getResults());

    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<Hypervel\Types\Relations\User, Hypervel\Types\Relations\Post>', $post->user());
    assertType('Hypervel\Types\Relations\User|null', $post->user()->getResults());
    assertType('Hypervel\Types\Relations\User', $post->user()->make());
    assertType('Hypervel\Types\Relations\User', $post->user()->create());
    assertType('Hypervel\Types\Relations\Post', $post->user()->associate(new User()));
    assertType('Hypervel\Types\Relations\Post', $post->user()->dissociate());
    assertType('Hypervel\Types\Relations\Post', $post->user()->getChild());

    assertType('Hypervel\Database\Eloquent\Relations\MorphOne<Hypervel\Types\Relations\Image, Hypervel\Types\Relations\Post>', $post->image());
    assertType('Hypervel\Types\Relations\Image|null', $post->image()->getResults());
    assertType('Hypervel\Types\Relations\Image', $post->image()->forceCreate([]));

    assertType('Hypervel\Database\Eloquent\Relations\MorphMany<Hypervel\Types\Relations\Comment, Hypervel\Types\Relations\Post>', $post->comments());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Comment>', $post->comments()->getResults());

    assertType('Hypervel\Database\Eloquent\Relations\MorphTo<Hypervel\Database\Eloquent\Model, Hypervel\Types\Relations\Comment>', $comment->commentable());
    assertType('Hypervel\Database\Eloquent\Model|null', $comment->commentable()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Comment>', $comment->commentable()->getEager());
    assertType('Hypervel\Database\Eloquent\Model', $comment->commentable()->createModelByType('foo'));
    assertType('Hypervel\Types\Relations\Comment', $comment->commentable()->associate(new Post()));
    assertType('Hypervel\Types\Relations\Comment', $comment->commentable()->dissociate());

    assertType("Hypervel\\Database\\Eloquent\\Relations\\MorphToMany<Hypervel\\Types\\Relations\\Tag, Hypervel\\Types\\Relations\\Post, Hypervel\\Database\\Eloquent\\Relations\\MorphPivot, 'pivot'>", $post->tags());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Relations\Tag&object{pivot: Hypervel\Database\Eloquent\Relations\MorphPivot}>', $post->tags()->getResults());

    assertType('42', Relation::noConstraints(fn () => 42));
}

class User extends Model
{
    /** @return HasOne<Address, $this> */
    public function address(): HasOne
    {
        $hasOne = $this->hasOne(Address::class);
        assertType('Hypervel\Database\Eloquent\Relations\HasOne<Hypervel\Types\Relations\Address, $this(Hypervel\Types\Relations\User)>', $hasOne);

        return $hasOne;
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        $hasMany = $this->hasMany(Post::class);
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\Relations\Post, $this(Hypervel\Types\Relations\User)>', $hasMany);

        return $hasMany;
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        $belongsToMany = $this->belongsToMany(Role::class);
        assertType('Hypervel\Database\Eloquent\Relations\BelongsToMany<Hypervel\Types\Relations\Role, $this(Hypervel\Types\Relations\User), Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>', $belongsToMany);

        return $belongsToMany;
    }

    /** @return HasOne<Mechanic, $this> */
    public function mechanic(): HasOne
    {
        return $this->hasOne(Mechanic::class);
    }

    /** @return HasMany<Mechanic, $this> */
    public function mechanics(): HasMany
    {
        return $this->hasMany(Mechanic::class);
    }

    /** @return HasOneThrough<Car, Mechanic, $this> */
    public function car(): HasOneThrough
    {
        $hasOneThrough = $this->hasOneThrough(Car::class, Mechanic::class);
        assertType('Hypervel\Database\Eloquent\Relations\HasOneThrough<Hypervel\Types\Relations\Car, Hypervel\Types\Relations\Mechanic, $this(Hypervel\Types\Relations\User)>', $hasOneThrough);

        return $hasOneThrough;
    }

    /** @return HasManyThrough<Part, Mechanic, $this> */
    public function parts(): HasManyThrough
    {
        $hasManyThrough = $this->hasManyThrough(Part::class, Mechanic::class);
        assertType('Hypervel\Database\Eloquent\Relations\HasManyThrough<Hypervel\Types\Relations\Part, Hypervel\Types\Relations\Mechanic, $this(Hypervel\Types\Relations\User)>', $hasManyThrough);

        return $hasManyThrough;
    }
}

class Post extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        $belongsTo = $this->belongsTo(User::class);
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<Hypervel\Types\Relations\User, $this(Hypervel\Types\Relations\Post)>', $belongsTo);

        return $belongsTo;
    }

    /** @return MorphOne<Image, $this> */
    public function image(): MorphOne
    {
        $morphOne = $this->morphOne(Image::class, 'imageable');
        assertType('Hypervel\Database\Eloquent\Relations\MorphOne<Hypervel\Types\Relations\Image, $this(Hypervel\Types\Relations\Post)>', $morphOne);

        return $morphOne;
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        $morphMany = $this->morphMany(Comment::class, 'commentable');
        assertType('Hypervel\Database\Eloquent\Relations\MorphMany<Hypervel\Types\Relations\Comment, $this(Hypervel\Types\Relations\Post)>', $morphMany);

        return $morphMany;
    }

    /** @return MorphToMany<Tag, $this> */
    public function tags(): MorphToMany
    {
        $morphToMany = $this->morphedByMany(Tag::class, 'taggable');
        assertType('Hypervel\Database\Eloquent\Relations\MorphToMany<Hypervel\Types\Relations\Tag, $this(Hypervel\Types\Relations\Post), Hypervel\Database\Eloquent\Relations\MorphPivot, \'pivot\'>', $morphToMany);

        return $morphToMany;
    }
}

class Comment extends Model
{
    /** @return MorphTo<\Hypervel\Database\Eloquent\Model, $this> */
    public function commentable(): MorphTo
    {
        $morphTo = $this->morphTo();
        assertType('Hypervel\Database\Eloquent\Relations\MorphTo<Hypervel\Database\Eloquent\Model, $this(Hypervel\Types\Relations\Comment)>', $morphTo);

        return $morphTo;
    }
}

class Tag extends Model
{
    /** @return MorphToMany<Post, $this> */
    public function posts(): MorphToMany
    {
        $morphToMany = $this->morphToMany(Post::class, 'taggable');
        assertType('Hypervel\Database\Eloquent\Relations\MorphToMany<Hypervel\Types\Relations\Post, $this(Hypervel\Types\Relations\Tag), Hypervel\Database\Eloquent\Relations\MorphPivot, \'pivot\'>', $morphToMany);

        return $morphToMany;
    }
}

class Mechanic extends Model
{
    /** @return HasOne<Car, $this> */
    public function car(): HasOne
    {
        return $this->hasOne(Car::class);
    }

    /** @return HasMany<Part, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }
}

class ChildUser extends User
{
}
class Address extends Model
{
}
class Role extends Model
{
}
class Car extends Model
{
}
class Part extends Model
{
}
class Image extends Model
{
}
