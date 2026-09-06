<?php

declare(strict_types=1);

namespace Hypervel\Types\Query\Builder;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\JoinClause;
use Hypervel\Database\Query\Processors\Processor;
use PDO;
use stdClass;
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

function testBindingSlots(Builder $query, CustomBindingBuilder $custom): void
{
    $query->addBinding(1, 'where');
    $query->setBindings([1], 'select');
    $query->cloneWithoutBindings(['where', 'order']);
    assertType('Hypervel\Database\Query\Builder', $query->newQuery());

    $query->addBinding(1, 'wher'); // @phpstan-ignore argument.type (Misspelled default slots must reject.)

    assertType('Hypervel\Types\Query\Builder\CustomBindingBuilder', $custom->addBinding(1, 'expressions'));
    assertType('Hypervel\Types\Query\Builder\CustomBindingBuilder', $custom->setBindings([2], 'expressions'));
    assertType("array<'expressions'|'from'|'groupBy'|'having'|'join'|'order'|'select'|'union'|'unionOrder'|'where', list<mixed>>", $custom->getRawBindings());
    assertType('Hypervel\Types\Query\Builder\CustomBindingBuilder', $custom->cloneWithoutBindings(['expressions', 'where']));
    assertType('Hypervel\Types\Query\Builder\CustomBindingBuilder', $custom->clone());
    assertType('Hypervel\Types\Query\Builder\CustomBindingBuilder', $custom->newQuery());
    assertType('Hypervel\Types\Query\Builder\CustomBindingBuilder', $custom->forNestedWhere());
    assertType('list<mixed>', $custom->forNestedWhere()->getRawBindings()['expressions']);
    assertType('Hypervel\Types\Query\Builder\CustomBindingBuilder', $custom->nestedExpressionBinding(1));
    $custom->clone()->addBinding(3, 'expressions');
    $custom->newQuery()->addBinding(4, 'expressions');
    $custom->forNestedWhere()->addBinding(5, 'expressions');
    $custom->setBindings([1], 'expression'); // @phpstan-ignore argument.type (Misspelled extension slots must reject.)
    $custom->cloneWithoutBindings(['expression']); // @phpstan-ignore argument.type (Cloning must validate the same slot type.)

    $custom->fetchUsing(PDO::FETCH_ASSOC)->addBinding(6, 'expressions');
    $custom->addBinding(7, 'expressions');
}

function testJoinBuilderFactories(JoinClause $join, CustomJoinClause $custom): void
{
    assertType('Hypervel\Database\Query\JoinClause', $join->newQuery());
    assertType('Hypervel\Database\Query\Builder', $custom->subQuery());
}

/** @extends Builder<int, stdClass, 'expressions'|'from'|'groupBy'|'having'|'join'|'order'|'select'|'union'|'unionOrder'|'where'> */
class CustomBindingBuilder extends Builder
{
    /**
     * Create a builder with an additional binding slot.
     */
    public function __construct(ConnectionInterface $connection, ?Grammar $grammar = null, ?Processor $processor = null)
    {
        parent::__construct($connection, $grammar, $processor);

        $this->bindings['expressions'] = [];
    }

    /**
     * Add a binding to the expression clause.
     */
    public function expressionBinding(mixed $value): static
    {
        return $this->addBinding($value, 'expressions');
    }

    /**
     * Add an expression binding through a nested query.
     */
    public function nestedExpressionBinding(mixed $value): static
    {
        return $this->mergeExpressionBindings($this->forNestedWhere()->expressionBinding($value));
    }

    /**
     * Merge another expression clause's bindings.
     */
    protected function mergeExpressionBindings(self $query): static
    {
        return $this->addBinding($query->getRawBindings()['expressions'], 'expressions');
    }

    /**
     * Verify protected factories retain the custom binding slot.
     */
    public function testProtectedFactoryTypes(): void
    {
        $this->cloneForPaginationCount()->expressionBinding(1);
        $this->forSubQuery()->addBinding(2, 'expressions');
    }
}

class CustomJoinClause extends JoinClause
{
    /**
     * Expose the parent query returned for join subqueries.
     */
    public function subQuery(): Builder
    {
        $query = $this->forSubQuery();

        assertType("Hypervel\\Database\\Query\\Builder<int, stdClass, 'from'|'groupBy'|'having'|'join'|'order'|'select'|'union'|'unionOrder'|'where'>", $query);

        return $query;
    }
}
