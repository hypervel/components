<?php

declare(strict_types=1);

namespace Hypervel\Types\Query\Builder;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Query\Builder;
use User;

use function PHPStan\Testing\assertType;

/** @param \Hypervel\Database\Eloquent\Builder<User> $userQuery */
function test(Builder $query, EloquentBuilder $userQuery): void
{
    assertType('object|null', $query->first());
    assertType('object|null', $query->find(1));
    assertType('42|object', $query->findOr(1, fn () => 42));
    assertType('42|object', $query->findOr(1, callback: fn () => 42));
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
    assertType('Hypervel\Support\LazyCollection<int, object>', $query->lazy());
    assertType('Hypervel\Support\LazyCollection<int, object>', $query->lazyById());
    assertType('Hypervel\Support\LazyCollection<int, object>', $query->lazyByIdDesc());

    $query->chunk(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, object>', $users);
        assertType('int', $page);
    });
    $query->chunkById(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, object>', $users);
        assertType('int', $page);
    });
    $query->chunkMap(function ($users) {
        assertType('object', $users);
    });
    $query->chunkByIdDesc(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, object>', $users);
        assertType('int', $page);
    });
    $query->each(function ($users, $page) {
        assertType('object', $users);
        assertType('int', $page);
    });
    $query->eachById(function ($users, $page) {
        assertType('object', $users);
        assertType('int', $page);
    });
    assertType('Hypervel\Database\Query\Builder', $query->pipe(function () {
    }));
    assertType('Hypervel\Database\Query\Builder', $query->pipe(fn () => null));
    assertType('Hypervel\Database\Query\Builder', $query->pipe(fn ($query) => $query));
    assertType('5', $query->pipe(fn ($query) => 5));
}
