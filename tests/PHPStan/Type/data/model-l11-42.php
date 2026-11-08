<?php

namespace Model;

use App\Account;
use App\Comment;
use App\Post;
use App\PostBuilder;
use App\Thread;
use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Foundation\Http\FormRequest;

use function PHPStan\Testing\assertType;

class AbstractModel extends Model
{
    public static function new(): static
    {
        assertType('static(Model\AbstractModel)', static::query()->create());
        return static::query()->create();
    }

    public function newQueryKeepsStatic(): void
    {
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\AbstractModel)>', $this->newQuery());
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\AbstractModel)>', $this->newModelQuery());
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\AbstractModel)>', $this->newQueryWithoutRelationships());
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\AbstractModel)>', $this->newQueryWithoutScopes());
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\AbstractModel)>', $this->newQueryWithoutScope('foo'));
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\AbstractModel)>', $this->newQueryForRestoration([1]));
    }
}

class Child extends AbstractModel {}

class TreeNode extends Model
{
    use SoftDeletes;

    /** @return HasMany<static, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(static::class);
    }

    public function forwardedLateStaticModel(): void
    {
        // Forwarded signatures keep the late-static model instead of the builder or relation they run on.
        assertType('static(Model\TreeNode)', static::restoreOrCreate());
        assertType('static(Model\TreeNode)|null', $this->children()->first());
        assertType('static(Model\TreeNode)', $this->children()->restoreOrCreate());
    }

    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        // A parent:: call of an instance method runs on $this, so the late-static model stays.
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\TreeNode)>', parent::setKeysForSaveQuery($query));

        return parent::setKeysForSaveQuery($query);
    }
}

class ChildPost extends Post
{
    public function parentQuery(): void
    {
        assertType('App\PostBuilder<static(Model\ChildPost)>', parent::newQuery());
    }
}

final class FinalModel extends Model
{
    public function newQueryOnFinalModel(): void
    {
        // `static` and the class name are the same type here, so the plain object type stays.
        assertType('Hypervel\Database\Eloquent\Builder<Model\FinalModel>', $this->newQuery());
        assertType('Hypervel\Database\Eloquent\Builder<Model\FinalModel>', $this->newModelQuery());
    }
}

class Foo
{
    public function __construct(private User $user)
    {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $this->user::query());
    }
}

class Bar extends Model
{
    use HasBar;

    public function test(): void
    {
        assertType('Hypervel\Database\Eloquent\Builder<Model\Bar>', self::query());
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\Bar)>', static::query());

        assertType('Model\Bar|null', self::query()->first());
        assertType('static(Model\Bar)|null', static::query()->first());
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\Bar)>', static::query()->orWhere('foo', 'bar'));
        assertType('Hypervel\Database\Eloquent\Builder<static(Model\Bar)>', static::query()->select('foo'));
    }
}

trait HasBar
{
    /** @return mixed[] */
    public static function decodeHashId(string $hash_id): array
    {
        return [];
    }

    public static function findByHashId(string $id): ?self
    {
        return self::find(static::decodeHashId($id))->first();
    }
}

/**
 * @param  class-string<Model>  $modelClass
 * @param  class-string<User>|class-string<Post>  $userOrPostClass
 * @param  class-string<User>|class-string<Account>  $userOrAccountClass
 */
function test(
    Model $model,
    string $modelClass,
    string $userOrPostClass,
    string $userOrAccountClass,
    FormRequest $request,
    User $user,
): void {
    /** @var array<string, string> $requestData */
    $requestData = $request->validated();

    assertType('Model\Child', Child::new());

    assertType('App\User|null', User::find(1));
    assertType('Model\Bar|null', Bar::findByHashId('1'));
    assertType('Hypervel\Database\Eloquent\Model|null', $model::find(1));
    assertType('Hypervel\Database\Eloquent\Model|null', $modelClass::find(1));
    assertType('App\Post|App\User|null', $userOrPostClass::find(1));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\Post>|Hypervel\Database\Eloquent\Collection<int, App\User>', $userOrPostClass::find([1, 2, 3]));
    assertType('App\AccountCollection<int, App\Account>|Hypervel\Database\Eloquent\Collection<int, App\User>', $userOrAccountClass::find([1, 2, 3]));
    assertType('App\AccountCollection<int, App\Account>|Hypervel\Database\Eloquent\Collection<int, App\User>', $userOrAccountClass::all());

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::find([1, 2, 3]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::findMany([1, 2, 3]));
    assertType('App\User', User::findOrFail(1));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::findOrFail([1, 2, 3]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::findOrFail([1, 2, 3])->makeHidden('foo'));
    assertType('App\User|null', User::findOrFail([1, 2, 3])->makeHidden('foo')->first());

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::find((array) $requestData['user_ids']));
    assertType('App\User|null', User::find((int) '1'));
    assertType('App\User', $user->make([]));
    assertType('Hypervel\Database\Query\Builder', User::getQuery());
    assertType('Hypervel\Database\Query\Builder', User::toBase());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::all());
    // Unlike upstream, a callback's result stays its own type rather than the called model's collection or builder.
    assertType('App\AccountCollection<int, App\Account>', User::withoutEvents(fn () => Account::all()));
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>', User::unguarded(fn () => Account::query()));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::join('tickets.tickets', 'tickets.tickets.id', '=', 'tickets.sale_ticket.ticket_id'));

    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', (new Thread)->where(['name' => 'bar']));
    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', Thread::where(['name' => 'bar']));
    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', (new Thread)->whereName(['bar']));
    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', Thread::whereName(['bar']));
    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', (new Thread)->whereIn('id', [1, 2, 3]));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', (new User)->withWhereHas('accounts', function ($query) {
        return $query->where('active', true);
    }));

    assertType('int|false', $user->increment('counter'));
    assertType('int|false', $user->decrement('counter'));
    assertType('int', $user->incrementQuietly('counter'));
    assertType('int', $user->decrementQuietly('counter'));

    assertType('App\User|null', User::first());
    assertType('App\User', User::make([]));
    assertType('App\User', User::create([]));
    assertType('App\User', User::forceCreate([]));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::findOrNew([]));
    assertType('App\User', User::firstOrNew([]));
    assertType('App\User', User::updateOrCreate([]));
    assertType('App\User', User::firstOrCreate([]));
    assertType('App\User', User::createOrFirst([]));

    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', Thread::valid());
    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', Thread::valid()->orWhere->valid());
    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', Thread::valid()->orWhereNot->valid());
    assertType('Hypervel\Database\Eloquent\Builder<App\Thread>', Thread::valid()->whereNot->valid());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with(['accounts' => function ($relation) {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);

        return $relation->where('active', true);
    }]));
    // Hypervel: each key's callback receives that key's own relation, not a union of the keys' relations.
    $user->with([
        'accounts' => function ($relation) {
            assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
        },
        'group' => function ($relation) {
            assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
        },
    ]);
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', (new User)->withGlobalScope('test', function () {
    }));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', (new User)->withoutGlobalScope('test'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::onlyTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::withTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::withTrashed(false));
    assertType('App\User', User::onlyTrashed()->findOrFail(5));
    assertType('bool', $user->restore());
    assertType('App\User', User::restoreOrCreate(['id' => 1]));
    assertType('App\User|null', User::firstWhere(['email' => 'foo@bar.com']));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->with('accounts'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->with('accounts')->with('group'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->with(['accounts' => function (Relation $relation) {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation->orderBy('id'));
    }]));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::lockForUpdate());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::sharedLock());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query());

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', Thread::methodReturningCollectionOfAnotherModel());
    assertType('App\Thread|Hypervel\Database\Eloquent\Collection<int, App\Thread>', Thread::methodReturningUnionWithCollection());
    assertType('App\User|Hypervel\Database\Eloquent\Collection<int, App\User>', Thread::methodReturningUnionWithCollectionOfAnotherModel());
    assertType('mixed', $user->min('id'));
    assertType('App\User', User::sole());

    /** @var class-string<User> $className */
    $className = User::class;

    assertType('App\User', new $className());
    assertType('App\User', $className::create());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', (new $className())->newQuery());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $className::query());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $className::query()->active());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $className::query()->active()->get());

    assertType('string', Thread::valid()->toRawSql());
    // Unlike upstream, a passthru dumpRawSql() returns the raw query that toBase() gives it, whose rows are not models.
    assertType('Hypervel\Database\Query\Builder', Thread::valid()->dumpRawSql());
    assertType('stdClass|null', Thread::valid()->dumpRawSql()->first());

    User::has('accounts', '=', 1, 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::has('users', '=', 1, 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::doesntHave('accounts', 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::doesntHave('users', 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::where(function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Post::where(function (PostBuilder $query) {
        assertType('App\PostBuilder<App\Post>', $query);
    });

    User::orWhere(function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Post::orWhere(function (PostBuilder $query) {
        assertType('App\PostBuilder<App\Post>', $query);
    });

    User::whereHas('accounts', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::whereHas('users', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::withWhereHas('accounts', function (Builder|Relation $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $query);
    });

    Post::withWhereHas('users', function (Builder|Relation $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Eloquent\Relations\BelongsToMany<App\User, App\Post, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>', $query);
    });

    User::orWhereHas('accounts', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::orWhereHas('users', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::hasMorph('commentable', [Account::class], '=', 1, 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::hasMorph('commentable', [User::class], '=', 1, 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::doesntHaveMorph('commentable', [Account::class], 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::doesntHaveMorph('commentable', [User::class], 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::whereHasMorph('commentable', [Account::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::whereHasMorph('commentable', [User::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::orWhereHasMorph('commentable', [Account::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::orWhereHasMorph('commentable', [User::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::whereDoesntHaveMorph('commentable', [Account::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::whereDoesntHaveMorph('commentable', [User::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::orWhereDoesntHaveMorph('commentable', [Account::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::orWhereDoesntHaveMorph('commentable', [User::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::whereDoesntHave('accounts', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::whereDoesntHave('users', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::orWhereDoesntHave('accounts', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::orWhereDoesntHave('users', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::firstWhere(function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Post::firstWhere(function (PostBuilder $query) {
        assertType('App\PostBuilder<App\Post>', $query);
    });
}
