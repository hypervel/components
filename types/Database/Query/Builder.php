<?php

declare(strict_types=1);

namespace Hypervel\Types\Query\Builder;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Query\Builder;
use PDO;
use User;

use function PHPStan\Testing\assertType;

/** @param \Hypervel\Database\Eloquent\Builder<User> $userQuery */
function test(Builder $query, EloquentBuilder $userQuery): void
{
    assertType('stdClass|null', $query->first());
    assertType('stdClass|null', $query->find(1));
    assertType('42|stdClass', $query->findOr(1, fn () => 42));
    assertType('42|stdClass', $query->findOr(1, callback: fn () => 42));
    assertType('Hypervel\Support\Collection<int, stdClass>', $query->get());
    assertType('Hypervel\Support\LazyCollection<int, stdClass>', $query->cursor());
    assertType('Hypervel\Database\Query\Builder', $query->selectSub($userQuery, 'alias'));
    assertType('Hypervel\Database\Query\Builder', $query->fromSub($userQuery, 'alias'));
    assertType('Hypervel\Database\Query\Builder', $query->from($userQuery, 'alias'));
    assertType('Hypervel\Database\Query\Builder', $query->joinSub($userQuery, 'alias', 'foo'));
    assertType('Hypervel\Database\Query\Builder', $query->joinLateral($userQuery, 'alias'));
    assertType('Hypervel\Database\Query\Builder', $query->leftJoinLateral($userQuery, 'alias'));
    assertType('Hypervel\Database\Query\Builder', $query->leftJoinSub($userQuery, 'alias', 'foo'));
    assertType('Hypervel\Database\Query\Builder', $query->rightJoinSub($userQuery, 'alias', 'foo'));
    assertType('Hypervel\Database\Query\Builder', $query->crossJoinSub($userQuery, 'alias'));
    assertType('Hypervel\Database\Query\Builder', $query->whereExists($userQuery));
    assertType('Hypervel\Database\Query\Builder', $query->orWhereExists($userQuery));
    assertType('Hypervel\Database\Query\Builder', $query->whereNotExists($userQuery));
    assertType('Hypervel\Database\Query\Builder', $query->orWhereNotExists($userQuery));
    assertType('Hypervel\Database\Query\Builder', $query->orderBy($userQuery));
    assertType('Hypervel\Database\Query\Builder', $query->orderByDesc($userQuery));
    assertType('Hypervel\Database\Query\Builder', $query->union($userQuery));
    assertType('Hypervel\Database\Query\Builder', $query->unionAll($userQuery));
    assertType('int', $query->insertUsing([], $userQuery));
    assertType('int', $query->insertOrIgnoreUsing([], $userQuery));
    assertType('Hypervel\Support\LazyCollection<int, stdClass>', $query->lazy());
    assertType('Hypervel\Support\LazyCollection<int, stdClass>', $query->lazyById());
    assertType('Hypervel\Support\LazyCollection<int, stdClass>', $query->lazyByIdDesc());
    assertType('Hypervel\Pagination\LengthAwarePaginator', $query->paginate());
    assertType('Hypervel\Contracts\Pagination\Paginator', $query->simplePaginate());
    assertType('Hypervel\Contracts\Pagination\CursorPaginator', $query->cursorPaginate());
    assertType('Hypervel\Database\Eloquent\Collection<int, User>', $userQuery->get());
    assertType('User|null', $userQuery->first());
    assertType(
        'Hypervel\Database\Eloquent\Collection<int, User>',
        $userQuery->fetchUsing(PDO::FETCH_ASSOC)->get()
    );

    $query->chunk(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, stdClass>', $users);
        assertType('int', $page);
    });
    $query->chunkById(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, stdClass>', $users);
        assertType('int', $page);
    });
    $query->chunkMap(function ($users) {
        assertType('stdClass', $users);
    });
    $query->chunkByIdDesc(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, stdClass>', $users);
        assertType('int', $page);
    });
    $query->each(function ($users, $page) {
        assertType('stdClass', $users);
        assertType('int', $page);
    });
    $query->eachById(function ($users, $page) {
        assertType('stdClass', $users);
        assertType('int', $page);
    });
    assertType('Hypervel\Database\Query\Builder', $query->pipe(function () {
    }));
    assertType('Hypervel\Database\Query\Builder', $query->pipe(fn () => null));
    assertType('Hypervel\Database\Query\Builder', $query->pipe(fn ($query) => $query));
    assertType('5', $query->pipe(fn ($query) => 5));
}

/** @param \Hypervel\Database\Eloquent\Builder<User> $userQuery */
function testStatementEloquentFetchUsing(EloquentBuilder $userQuery): void
{
    $userQuery->fetchUsing(PDO::FETCH_ASSOC);

    assertType('Hypervel\Database\Eloquent\Collection<int, User>', $userQuery->get());
    assertType('User|null', $userQuery->first());
}

function testChainedFetchUsing(Builder $query): void
{
    assertType(
        'Hypervel\Support\Collection<(int|string), mixed>',
        $query->fetchUsing(PDO::FETCH_ASSOC)->get()
    );
    assertType(
        'Hypervel\Support\Collection<(int|string), mixed>',
        $query->fetchUsing(PDO::FETCH_ASSOC)->where('active', true)->get()
    );
}

function testStatementFetchUsing(Builder $query): void
{
    $query->fetchUsing(PDO::FETCH_UNIQUE);

    assertType('Hypervel\Support\Collection<(int|string), mixed>', $query->get());
    assertType('Hypervel\Support\LazyCollection<int, mixed>', $query->cursor());
    assertType('Hypervel\Support\LazyCollection<int, mixed>', $query->lazy());
    assertType('Hypervel\Support\LazyCollection<int, mixed>', $query->lazyById());

    $query->chunk(1, function ($items, $page): void {
        assertType('Hypervel\Support\Collection<(int|string), mixed>', $items);
        assertType('int', $page);
    });
    $query->each(function ($item, $position): void {
        assertType('mixed', $item);
        assertType('int', $position);
    });
}

function testFetchUsingResetRemainsConservative(Builder $query): void
{
    $query->fetchUsing();

    assertType('Hypervel\Support\Collection<(int|string), mixed>', $query->get());
}
