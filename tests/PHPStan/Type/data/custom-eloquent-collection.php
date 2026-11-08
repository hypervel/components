<?php

namespace CustomEloquentCollection;

use App\Account;
use App\Group;
use App\ModelWithNonGenericCollection;
use App\ModelWithOnlyValueGenericCollection;
use App\Role;
use App\User;
use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('App\AccountCollection<int, App\Account>', Account::query()->fromQuery('select * from accounts'));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->fromQuery('select * from accounts'));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->fromQuery('select * from accounts'));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->fromQuery('select * from accounts'));

    assertType('App\AccountCollection<int, App\Account>', Account::fromQuery('select * from accounts'));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::fromQuery('select * from accounts'));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::fromQuery('select * from accounts'));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::fromQuery('select * from accounts'));

    assertType('App\AccountCollection<int, App\Account>', Account::query()->hydrate([['active' => 1], ['active' => 0]]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->hydrate([['active' => 1], ['active' => 0]]));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->hydrate([['active' => 1], ['active' => 0]]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->hydrate([['active' => 1], ['active' => 0]]));

    assertType('App\AccountCollection<int, App\Account>', Account::hydrate([['active' => 1], ['active' => 0]]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::hydrate([['active' => 1], ['active' => 0]]));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::hydrate([['active' => 1], ['active' => 0]]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::hydrate([['active' => 1], ['active' => 0]]));

    assertType('App\AccountCollection<int, App\Account>', Account::query()->find([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->find([1, 2]));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->find([1, 2]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->find([1, 2]));

    assertType('App\AccountCollection<int, App\Account>', Account::find([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::find([1, 2]));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::find([1, 2]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::find([1, 2]));

    assertType('App\AccountCollection<int, App\Account>', Account::query()->findMany([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->findMany([1, 2]));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->findMany([1, 2]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->findMany([1, 2]));

    assertType('App\AccountCollection<int, App\Account>', Account::findMany([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::findMany([1, 2]));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::findMany([1, 2]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::findMany([1, 2]));

    assertType('App\AccountCollection<int, App\Account>', Account::query()->findOrFail([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->findOrFail([1, 2]));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->findOrFail([1, 2]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->findOrFail([1, 2]));

    assertType('App\AccountCollection<int, App\Account>', Account::findOrFail([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::findOrFail([1, 2]));
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::findOrFail([1, 2]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::findOrFail([1, 2]));

    // Hypervel: findOr() calls the callback when any of the keys is missing.
    assertType('42|App\AccountCollection<int, App\Account>', Account::query()->findOr([1, 2], fn () => 42));
    assertType('42|Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->findOr([1, 2], fn () => 42));
    assertType('42|App\NonGenericCollection', ModelWithNonGenericCollection::query()->findOr([1, 2], fn () => 42));
    assertType('42|App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->findOr([1, 2], fn () => 42));

    assertType('42|App\AccountCollection<int, App\Account>', Account::findOr([1, 2], fn () => 42));
    assertType('42|Hypervel\Database\Eloquent\Collection<int, App\User>', User::findOr([1, 2], fn () => 42));

    assertType('App\AccountCollection<int, App\Account>', Account::query()->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::query()->get());
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::query()->get());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::query()->get());

    assertType('App\AccountCollection<int, App\Account>', Account::get());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::get());
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::get());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::get());

    assertType('App\AccountCollection<int, App\Account>', Account::all());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::all());
    assertType('App\NonGenericCollection', ModelWithNonGenericCollection::all());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', ModelWithOnlyValueGenericCollection::all());

    assertType('App\AccountCollection<int, App\Account>', (new User)->accounts()->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children()->get());
    assertType('App\NonGenericCollection', (new User)->modelsWithNonGenericCollection()->get());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', (new User)->modelsWithOnlyValueGenericCollection()->get());

    assertType('App\AccountCollection<int, App\Account>', (new User)->accounts()->getEager());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children()->getEager());
    assertType('App\NonGenericCollection', (new User)->modelsWithNonGenericCollection()->getEager());
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', (new User)->modelsWithOnlyValueGenericCollection()->getEager());

    assertType('App\AccountCollection<int, App\Account>', (new User)->accounts()->createMany([]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children()->createMany([]));
    assertType('App\NonGenericCollection', (new User)->modelsWithNonGenericCollection()->createMany([]));
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', (new User)->modelsWithOnlyValueGenericCollection()->createMany([]));

    assertType('App\AccountCollection<int, App\Account>', (new User)->accounts()->active()->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children()->active()->get());

    assertType('App\AccountCollection<int, App\Account>', (new User)->accounts);
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children);
    assertType('App\NonGenericCollection', (new User)->modelsWithNonGenericCollection);
    assertType('App\OnlyValueGenericCollection<App\ModelWithOnlyValueGenericCollection>', (new User)->modelsWithOnlyValueGenericCollection);

    // Hypervel: BelongsToMany results carry their pivot, as single results do.
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new User)->roles()->find([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new Role)->users()->find([1, 2]));

    assertType('(App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot})|null', (new User)->roles()->find(1));
    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new User)->roles()->findOrFail([1, 2]));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new Role)->users()->findOrFail([1, 2]));
    assertType('App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}', (new User)->roles()->findOrFail(1));

    assertType('App\RoleCollection<int, App\Role&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new User)->roles()->findMany([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new Role)->users()->findMany([1, 2]));

    assertType('App\TransactionCollection<int, App\Transaction>', (new User)->transactions()->find([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new Role)->users()->find([1, 2]));
    assertType('App\Transaction|null', (new User)->transactions()->find(1));
    assertType('App\TransactionCollection<int, App\Transaction>', (new User)->transactions()->findOrFail([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new Role)->users()->findOrFail([1, 2]));
    assertType('App\Transaction', (new User)->transactions()->findOrFail(1));
    assertType('App\TransactionCollection<int, App\Transaction>', (new User)->transactions()->findMany([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User&object{pivot: Hypervel\Database\Eloquent\Relations\Pivot}>', (new Role)->users()->findMany([1, 2]));
    assertType('App\AccountCollection<int, App\Account>', (new Group)->accounts()->find([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children()->find([1, 2]));
    assertType('App\Account|null', (new Group)->accounts()->find(1));
    assertType('App\AccountCollection<int, App\Account>', (new Group)->accounts()->findOrFail([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children()->findOrFail([1, 2]));
    assertType('App\Account', (new Group)->accounts()->findOrFail(1));
    assertType('App\AccountCollection<int, App\Account>', (new Group)->accounts()->findMany([1, 2]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children()->findMany([1, 2]));
    assertType('App\AccountCollection<int, App\Account>', (new User)->accounts->where('active', true));
    assertType('App\AccountCollection<int, App\Account>', (new User)->accounts->filterByActive());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->children->where('active', true));
}

/**
 * @param \Hypervel\Database\Eloquent\Collection<int, \App\Post> $posts
 * @param \Hypervel\Database\Eloquent\Collection<int, \App\Team> $teams
 * @param \Hypervel\Database\Eloquent\Collection<int, User> $users
 * @param \Hypervel\Database\Eloquent\Collection<int, \App\Post|\App\Team> $union
 * @param \Hypervel\Database\Eloquent\Collection<int, \CustomEloquentBuilder\ModelWithOwnModelQuery> $ownModelQuery
 * @param \Hypervel\Database\Eloquent\Collection<int, *> $unknown
 */
function testToQuery($posts, $teams, $users, $union, $ownModelQuery, $unknown): void
{
    assertType('App\PostBuilder<App\Post>', $posts->toQuery());
    assertType('App\ChildTeamBuilder', $teams->toQuery());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $users->toQuery());
    assertType('App\ChildTeamBuilder|App\PostBuilder<App\Post>', $union->toQuery());
    // Hypervel: toQuery() runs the model's newModelQuery(), including an override, and an unknown model has an
    // unknown builder.
    assertType('CustomEloquentBuilder\OwnModelQueryBuilder<CustomEloquentBuilder\ModelWithOwnModelQuery>', $ownModelQuery->toQuery());
    assertType('Hypervel\Database\Eloquent\Builder<*>', $unknown->toQuery());
}
