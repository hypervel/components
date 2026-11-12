<?php

namespace ModelRelations;

use App\Account;
use App\Address;
use App\BareRelations\Shelf;
use App\ChildUser;
use App\Comment;
use App\Group;
use App\Post;
use App\Role;
use App\Tag;
use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;
use function PHPStan\Testing\assertType;

function test(
    User $appUser,
    Address $address,
    Account $account,
    ExtendsModelWithPropertyAnnotations $model,
    Tag $tag,
    User|Account $union,
    User $user,
    Post $post,
    Comment $comment,
    ChildUser $child,
): void {
    assertType('App\Account', $appUser->accounts()->firstOrCreate([]));
    assertType('App\Account', $appUser->accounts()->createOrFirst([]));
    // Unlike upstream, only models loaded through the relation's own query are typed with their pivot.
    assertType('App\Post', $appUser->posts()->create());
    assertType('App\Account', $appUser->accounts()->create());
    assertType('App\Account', $appUser->syncableRelation()->create());
    assertType('int', $appUser->accounts()->increment('id'));
    assertType('int', $appUser->accounts()->decrement('id'));
    assertType('int', $appUser->accounts()->increment('id', 5));
    assertType('int', $appUser->accounts()->decrement('id', 5));
    // Unlike upstream, the paginator names its collection class.
    assertType('Hypervel\Pagination\LengthAwarePaginator<int, App\Account, App\AccountCollection<int, App\Account>>', $appUser->accounts()->paginate(5));
    assertType(
        'Hypervel\Database\Eloquent\Relations\MorphTo<Hypervel\Database\Eloquent\Model, App\Address>',
        $address->addressable()->where('name', 'bar')
    );
    assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Address, App\User>', $appUser->address()->where('name', 'bar'));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $appUser->accounts()->active());
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $appUser->roles()->get());
    /** @var Group $group */
    $group = $appUser->group;

    $appUser->__children = $appUser->children;

    assertType('App\AccountCollection<int, App\Account>', $group->accounts()->where('active', 1)->get());
    assertType('App\Account', $appUser->accounts()->make());
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $appUser->roles()->find([1]));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $appUser->roles()->findMany([1, 2, 3]));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $appUser->roles()->findOrNew([1]));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $appUser->roles()->findOrFail([1]));
    assertType('42|App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $appUser->roles()->findOr([1], fn () => 42));
    assertType('42|App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $appUser->roles()->findOr([1], callback: fn () => 42));
    assertType('App\Role', $appUser->roles()->findOrNew(1));
    assertType('App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $appUser->roles()->findOrFail(1));
    assertType('(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $appUser->roles()->find(1));
    // Unlike upstream, which leaves the scalar-ID findOr() assertions disabled.
    assertType('42|(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})', $appUser->roles()->findOr(1, fn () => 42));
    assertType('42|(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})', $appUser->roles()->findOr(1, callback: fn () => 42));
    assertType('(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $appUser->roles()->first());
    assertType('42|(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})', $appUser->roles()->firstOr(fn () => 42));
    assertType('42|(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})', $appUser->roles()->firstOr(callback: fn () => 42));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', (new User())->accounts()->where('name', 'bar'));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', (new User())->accounts()->whereIn('id', [1, 2, 3]));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', (new User())->accounts()->whereActive(true));
    assertType('App\Account', $appUser->accounts()->create());
    assertType('App\Account|null', (new User())->accounts()->where('name', 'bar')->first());
    assertType('App\User', User::with('accounts')->whereHas('accounts')->firstOrFail());
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\User, App\Account>', $account->ownerRelation());
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Account, App\Account>', $account->parent());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\User, App\User>', $appUser->children());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $appUser->__children);
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\User, App\User>', $appUser->parent());
    assertType('App\Account|null', $appUser->accounts()->firstWhere('name', 'bar'));
    assertType('App\Group|null', $appUser->group()->firstWhere('name', 'bar'));
    assertType('App\Account|null', $appUser->accounts->first());
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $appUser->group()->withTrashed());
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $appUser->group()->onlyTrashed());
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $appUser->group()->withoutTrashed());
    assertType('Hypervel\Database\Eloquent\Relations\HasManyThrough<App\Transaction, App\Account, App\User>', $appUser->transactions());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with([
        'accounts' => function (HasMany $query) {
            return $query->where('foo', 'bar');
        },
    ]));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with([
        'group' => [
            'accounts',
        ],
    ]));
    assertType('App\User|null', $model->nullableUser);
    assertType('App\User', $model->nonNullableUser);
    assertType('string|null', $model->nullableFoo);
    assertType('string', $model->nonNullableFoo);

    // Relationship counts
    assertType('int<0, max>', $appUser->group_count);
    assertType('int<0, max>', $appUser->accounts_count);
    assertType('int<0, max>', $appUser->accounts_snake_count);
    assertType('int<0, max>', $appUser->accounts_camel_count);
    assertType('int<0, max>', $appUser->accountsCamel_count);
    assertType('int<0, max>', $appUser->syncable_relation_count);

    $users = (new Post())->users();
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->getEager());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $users->get());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $users->getQuery());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $users->getRelationExistenceCountQuery(User::query(), Post::query()));
    assertType('App\PostBuilder<App\Post>', $appUser->posts()->getRelationExistenceCountQuery(Post::query(), User::query()));
    assertType('App\User', $users->make());
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\Account>|Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $union->group());
    assertType("Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Post, App\Account, Hypervel\Database\Eloquent\Relations\Pivot, 'pivot'>|Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Post, App\User, Hypervel\Database\Eloquent\Relations\Pivot, 'pivot'>", $union->posts());

    assertType('App\Account', $appUser->accounts()->sole());
    assertType('App\Group', $appUser->group()->sole());
    assertType('App\Post', $appUser->posts()->sole());

    assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Address, App\User>', $user->address());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Address>', $user->address()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Address>', $user->address);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Address>', $user->address()->get());
    assertType('App\Address', $user->address()->make());
    assertType('App\Address', $user->address()->create());
    assertType('Hypervel\Database\Eloquent\Relations\HasOne<App\Address, App\ChildUser>', $child->oneAddress());
    assertType('App\Address', $child->oneAddress()->make());
    assertType('App\Address', $child->oneAddress()->create([]));
    assertType('App\Address', $child->oneAddress()->getRelated());
    assertType('App\ChildUser', $child->oneAddress()->getParent());
    assertType('Hypervel\Database\Eloquent\Relations\HasOne<App\Address, App\User>', $user->oneAddress());
    assertType('Hypervel\Database\Eloquent\Relations\HasOne<App\Address, App\User>', $user->oneAddress()->where('zip'));
    assertType('Hypervel\Database\Eloquent\Relations\HasOne<App\Address, App\User>', $user->oneAddress()->orderBy('zip'));

    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $user->accountsCamel());
    assertType('App\AccountCollection<int, App\Account>', $user->accountsCamel()->getResults());
    assertType('App\AccountCollection<int, App\Account>', $user->accountsCamel);
    assertType('App\AccountCollection<int, App\Account>', $user->accountsCamel()->makeMany([]));
    assertType('App\AccountCollection<int, App\Account>', $user->accountsCamel()->createMany([]));
    assertType('App\AccountCollection<int, App\Account>', $user->accountsCamel()->createManyQuietly([]));
    assertType('App\Account', $user->accountsCamel()->make());
    assertType('App\Account', $user->accountsCamel()->create());
    assertType('App\Account|false', $user->accountsCamel()->save(new Account()));
    assertType('App\Account|false', $user->accountsCamel()->saveQuietly(new Account()));

    assertType('Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Role, App\User, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>', $user->roles());
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->getResults());
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles);
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->find([1]));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findMany([1, 2, 3]));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findOrNew([1]));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findOrFail([1]));
    assertType('42|App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findOr([1], fn () => 42));
    assertType('42|App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->findOr([1], callback: fn () => 42));
    assertType('App\Role', $user->roles()->findOrNew(1));
    assertType('App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->findOrFail(1));
    assertType('(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $user->roles()->find(1));
    assertType('42|(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})', $user->roles()->findOr(1, fn () => 42));
    assertType('42|(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})', $user->roles()->findOr(1, callback: fn () => 42));
    assertType('(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $user->roles()->first());
    assertType('42|(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})', $user->roles()->firstOr(fn () => 42));
    assertType('42|(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})', $user->roles()->firstOr(callback: fn () => 42));
    assertType('(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $user->roles()->firstWhere('foo'));
    assertType('App\Role', $user->roles()->firstOrNew());
    assertType('App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $user->roles()->firstOrFail());
    assertType('App\Role', $user->roles()->firstOrCreate());
    assertType('App\Role', $user->roles()->create());
    assertType('App\Role', $user->roles()->createOrFirst());
    assertType('App\Role', $user->roles()->updateOrCreate([]));
    assertType('App\Role', $user->roles()->save(new Role()));
    assertType('App\Role', $user->roles()->saveQuietly(new Role()));
    $roles = $user->roles()->getResults();
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->saveMany($roles));
    assertType('array<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->saveMany($roles->all()));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->saveManyQuietly($roles));
    assertType('array<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->saveManyQuietly($roles->all()));
    assertType('array<int, App\Role>', $user->roles()->createMany($roles));
    assertType('array{attached: array, detached: array, updated: array}', $user->roles()->sync($roles));
    assertType('array{attached: array, detached: array, updated: array}', $user->roles()->syncWithoutDetaching($roles));
    assertType('array{attached: array, detached: array, updated: array}', $user->roles()->syncWithPivotValues($roles, []));
    assertType('Hypervel\Support\LazyCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->lazy());
    assertType('Hypervel\Support\LazyCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->lazyById());
    assertType('Hypervel\Support\LazyCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $user->roles()->cursor());

    assertType('Hypervel\Database\Eloquent\Relations\HasOneThrough<App\Car, App\Mechanic, App\User>', $user->car());
    assertType('App\Car|null', $user->car()->getResults());
    assertType('App\Car|null', $user->car);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Car>', $user->car()->find([1]));
    assertType('42|Hypervel\Database\Eloquent\Collection<int, App\Car>', $user->car()->findOr([1], fn () => 42));
    assertType('42|Hypervel\Database\Eloquent\Collection<int, App\Car>', $user->car()->findOr([1], callback: fn () => 42));
    assertType('App\Car|null', $user->car()->find(1));
    assertType('42|App\Car', $user->car()->findOr(1, fn () => 42));
    assertType('42|App\Car', $user->car()->findOr(1, callback: fn () => 42));
    assertType('App\Car|null', $user->car()->first());
    assertType('42|App\Car', $user->car()->firstOr(fn () => 42));
    assertType('42|App\Car', $user->car()->firstOr(callback: fn () => 42));
    assertType('Hypervel\Support\LazyCollection<int, App\Car>', $user->car()->lazy());
    assertType('Hypervel\Support\LazyCollection<int, App\Car>', $user->car()->lazyById());
    assertType('Hypervel\Support\LazyCollection<int, App\Car>', $user->car()->cursor());
    // Unlike upstream, through() with a relation name also names the local relationship.
    assertType('Hypervel\Database\Eloquent\PendingHasThroughRelationship<Hypervel\Database\Eloquent\Model, App\User, Hypervel\Database\Eloquent\Relations\HasOneOrMany<Hypervel\Database\Eloquent\Model, App\User, *>>', $user->through('mechanic'));
    // Hypervel: __call() turns through{Relation}() into through('relation'), and has{Relation}() into has('relation').
    assertType('Hypervel\Database\Eloquent\PendingHasThroughRelationship<Hypervel\Database\Eloquent\Model, App\User, Hypervel\Database\Eloquent\Relations\HasOneOrMany<Hypervel\Database\Eloquent\Model, App\User, *>>', $user->throughMechanic());
    assertType('Hypervel\Database\Eloquent\Relations\HasManyThrough<Hypervel\Database\Eloquent\Model, Hypervel\Database\Eloquent\Model, App\User>|Hypervel\Database\Eloquent\Relations\HasOneThrough<Hypervel\Database\Eloquent\Model, Hypervel\Database\Eloquent\Model, App\User>', $user->throughMechanic()->hasParts());

    // Hypervel: a relation resolveRelationUsing() registers is typed by its resolver's declared return.
    assertType('Hypervel\Database\Eloquent\Relations\HasMany', $user->dynamicAccounts());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Database\Eloquent\Model>', $user->dynamicAccounts);
    assertType('int<0, max>', $user->dynamicAccounts_count);
    assertType('mixed', $user->untypedAccounts());
    assertType('mixed', $user->untypedAccounts);

    assertType('Hypervel\Database\Eloquent\Relations\HasManyThrough<App\Part, App\Mechanic, App\User>', $user->parts());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Part>', $user->parts()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Part>', $user->parts);
    assertType('Hypervel\Database\Eloquent\Relations\HasOneThrough<App\Part, App\Mechanic, App\User>', $user->firstPart());

    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\User, App\Post>', $post->user());
    assertType('App\User|null', $post->user()->getResults());
    assertType('App\User|null', $post->user);
    assertType('App\User', $post->user()->make());
    assertType('App\User', $post->user()->create());
    assertType('App\Post', $post->user()->associate(new User()));
    assertType('App\Post', $post->user()->dissociate());
    assertType('App\Post', $post->user()->disassociate());
    assertType('App\Post', $post->user()->getChild());

    assertType('Hypervel\Database\Eloquent\Relations\MorphOne<App\Image, App\Post>', $post->image());
    assertType('App\Image|null', $post->image()->getResults());
    assertType('App\Image|null', $post->image);
    assertType('App\Image', $post->image()->forceCreate([]));

    assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Comment, App\Post>', $post->comments());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Comment>', $post->comments()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Comment>', $post->comments);
    assertType('Hypervel\Database\Eloquent\Relations\MorphOne<App\Comment, App\Post>', $post->latestComment());

    assertType('Hypervel\Database\Eloquent\Relations\MorphTo<Hypervel\Database\Eloquent\Model, App\Comment>', $comment->commentable());
    assertType('Hypervel\Database\Eloquent\Model|null', $comment->commentable()->getResults());
    assertType('Hypervel\Database\Eloquent\Model|null', $comment->commentable);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Comment>', $comment->commentable()->getEager());
    assertType('Hypervel\Database\Eloquent\Model', $comment->commentable()->createModelByType('foo'));
    assertType('App\Comment', $comment->commentable()->associate(new Post()));
    assertType('App\Comment', $comment->commentable()->dissociate());

    assertType('Hypervel\Database\Eloquent\Relations\MorphToMany<App\Tag, App\Post, Hypervel\Database\Eloquent\Relations\MorphPivot, \'pivot\'>', $post->tags());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Tag&object{pivot: Hypervel\Database\Eloquent\Relations\MorphPivot}>', $post->tags()->getResults());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Tag&object{pivot: Hypervel\Database\Eloquent\Relations\MorphPivot}>', $post->tags);

    $user->roles()->where(function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Role>', $query);
    });

    assertType(
        'Hypervel\Database\Eloquent\Relations\HasManyThrough<App\Part, App\Mechanic, App\User>',
        $user->through($user->mechanic())->has(fn ($mechanic) => $mechanic->parts()),
    );

    assertType(
        'App\AccountCollection<int, App\Account>',
        $user->accountsCamel()->createMany([
            ['name' => 'foo'],
            ['name' => 'bar'],
        ]),
    );
}

/**
 * @property-read User $relation
 */
class RelationCreateExample extends Model
{
    /** @return HasMany<User, $this> */
    public function relation(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function addRelation(): User
    {
        return $this->relation()->create([]);
    }

    public function dynamicThroughForms(): void
    {
        // Hypervel: inside the model, the dynamic forms keep $this as the string forms do.
        assertType('Hypervel\Database\Eloquent\PendingHasThroughRelationship<Hypervel\Database\Eloquent\Model, $this(ModelRelations\RelationCreateExample), Hypervel\Database\Eloquent\Relations\HasOneOrMany<Hypervel\Database\Eloquent\Model, $this(ModelRelations\RelationCreateExample), *>>', $this->throughRelation());
        assertType('Hypervel\Database\Eloquent\Relations\HasManyThrough<Hypervel\Database\Eloquent\Model, Hypervel\Database\Eloquent\Model, $this(ModelRelations\RelationCreateExample)>|Hypervel\Database\Eloquent\Relations\HasOneThrough<Hypervel\Database\Eloquent\Model, Hypervel\Database\Eloquent\Model, $this(ModelRelations\RelationCreateExample)>', $this->throughRelation()->hasPosts());
    }
}

class ModelWithoutPropertyAnnotation extends Model
{
    /** @return HasMany<User, $this> */
    public function relation(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

/**
 * @property-read User|null   $nullableUser
 * @property-read User        $nonNullableUser
 * @property-read string|null $nullableFoo
 * @property-read string      $nonNullableFoo
 */
class ModelWithPropertyAnnotations extends Model
{
    /** @return HasOne<User, $this> */
    public function nullableUser(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** @return HasOne<User, $this> */
    public function nonNullableUser(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function getNullableFooAttribute(): ?string
    {
        return rand() ? 'foo' : null;
    }

    public function getNonNullableFooAttribute(): string
    {
        return 'foo';
    }
}

class ExtendsModelWithPropertyAnnotations extends ModelWithPropertyAnnotations
{
}

/** @extends HasMany<Post, User> */
class CustomBuilderHasMany extends HasMany
{
    public function testBuilder(): void
    {
        assertType('App\PostBuilder<App\Post>', $this->getRelationQuery());
        assertType('App\PostBuilder<App\Post>', $this->getQuery());
        assertType('Hypervel\Database\Query\Builder', $this->getBaseQuery());
    }
}

/** @extends \Hypervel\Database\Eloquent\Relations\BelongsToMany<\App\Team, User> */
class CustomBuilderBelongsToMany extends \Hypervel\Database\Eloquent\Relations\BelongsToMany
{
    public function testBuilder(): void
    {
        assertType('App\ChildTeamBuilder', $this->prepareQueryBuilder());
        assertType('App\ChildTeamBuilder', $this->getRelationQuery());
        assertType('App\ChildTeamBuilder', $this->getRelationExistenceCountQuery(\App\Team::query(), User::query()));

        $this->firstWhere(function ($query) {
            assertType('App\ChildTeamBuilder', $query);
        });
    }
}

/** @extends \Hypervel\Database\Eloquent\Relations\HasManyThrough<Post, User, \App\Team> */
class CustomBuilderHasManyThrough extends \Hypervel\Database\Eloquent\Relations\HasManyThrough
{
    public function testBuilder(): void
    {
        assertType('App\PostBuilder<App\Post>', $this->prepareQueryBuilder());
        assertType('App\PostBuilder<App\Post>', $this->getRelationQuery());
    }
}

function firstWhereCallbacks(User $user, string|null $operator): void
{
    assertType('(App\Post&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', $user->posts()->firstWhere(function ($query) {
        assertType('App\PostBuilder<App\Post>', $query);
    }));

    $user->roles()->firstWhere(function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Role>', $query);
    });

    $user->posts()->firstWhere(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, '=', 2);

    $user->posts()->firstWhere(value: 2, column: function ($query) {
        assertType('App\PostBuilder<App\Post>', $query);
    }, operator: null);

    $user->posts()->firstWhere(function ($query) {
        assertType('App\PostBuilder<App\Post>|Hypervel\Database\Query\Builder', $query);
    }, $operator, 2);
}

function chunkCallbacks(User $user): void
{
    $user->accounts()->chunk(10, fn ($accounts, $page) => assertType('App\AccountCollection<int, App\Account>', $accounts));
    $user->accounts()->each(fn ($account, $key) => assertType('App\Account', $account));

    $user->roles()->chunk(10, function ($roles, $page) {
        assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $roles);
        assertType('int', $page);
    });
    $user->roles()->chunkById(10, function ($roles, $page) {
        assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $roles);
        assertType('int', $page);
    });
    $user->roles()->chunkByIdDesc(10, function ($roles, $page) {
        assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $roles);
        assertType('int', $page);
    });
    $user->roles()->each(function ($role, $key) {
        assertType('App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $role);
        assertType('int', $key);
    });
    $user->roles()->eachById(function ($role, $key) {
        assertType('App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', $role);
        assertType('int', $key);
    });
    $user->posts()->where('id', '>', 1)->chunk(10, function ($posts) {
        assertType('Hypervel\Database\Eloquent\Collection<int, App\Post&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', $posts);
    });

    $user->parts()->chunk(10, function ($parts, $page) {
        assertType('Hypervel\Database\Eloquent\Collection<int, App\Part>', $parts);
        assertType('int', $page);
    });
    $user->parts()->chunkById(10, function ($parts, $page) {
        assertType('Hypervel\Database\Eloquent\Collection<int, App\Part>', $parts);
        assertType('int', $page);
    });
    $user->parts()->chunkByIdDesc(10, function ($parts, $page) {
        assertType('Hypervel\Database\Eloquent\Collection<int, App\Part>', $parts);
        assertType('int', $page);
    });
    $user->parts()->each(function ($part, $key) {
        assertType('App\Part', $part);
        assertType('int', $key);
    });
    $user->parts()->eachById(function ($part, $key) {
        assertType('App\Part', $part);
        assertType('int', $key);
    });
    $user->car()->chunk(10, function ($cars) {
        assertType('Hypervel\Database\Eloquent\Collection<int, App\Car>', $cars);
    });
}

function untypedRelation(Shelf $shelf): void
{
    // Hypervel: a method without a declared return type may be a relation of unknown type.
    assertType('mixed', $shelf->labels);
    assertType('int<0, max>', $shelf->labels_count);
    // The relation object is not what the property loads: a belongs-to relation can load null.
    assertType('mixed', $shelf->owner);
}
