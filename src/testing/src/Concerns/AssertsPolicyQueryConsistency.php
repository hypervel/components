<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;

/**
 * Assertions for verifying that a policy's query-aware methods (*Scope, *Select)
 * produce the same results as the per-instance PHP method.
 *
 * Use these in tests to catch drift between a policy's edit() method and its
 * editScope()/editSelect() counterparts.
 */
trait AssertsPolicyQueryConsistency
{
    /**
     * Assert that a policy's *Scope method filters to the same rows
     * as calling Gate::allows() on each model individually.
     *
     * @param iterable<Model> $models
     */
    protected function assertScopeMatchesPolicy(
        string $ability,
        Builder $baseQuery,
        iterable $models,
        mixed $user,
    ): void {
        $gate = $this->app->make(Gate::class)->forUser($user);

        $models = collect($models)->values();

        Assert::assertNotEmpty(
            $models,
            'assertScopeMatchesPolicy() requires at least one model.'
        );

        $keyName = $models->first()->getKeyName();

        $expectedIds = $models
            ->filter(fn (Model $model) => $gate->allows($ability, $model))
            ->pluck($keyName)
            ->sort()
            ->values()
            ->all();

        $scopedQuery = clone $baseQuery;

        $actualIds = $gate
            ->scope($ability, $scopedQuery)
            ->pluck($keyName)
            ->sort()
            ->values()
            ->all();

        Assert::assertSame(
            $expectedIds,
            $actualIds,
            "Policy [{$ability}] and [{$ability}Scope] returned different row sets."
        );
    }

    /**
     * Assert that a policy's *Select method produces the same boolean
     * per row as calling Gate::allows() on each model individually.
     *
     * @param iterable<Model> $models
     */
    protected function assertSelectMatchesPolicy(
        string $ability,
        Builder $baseQuery,
        iterable $models,
        mixed $user,
        ?string $columnName = null,
    ): void {
        $gate = $this->app->make(Gate::class)->forUser($user);

        $models = collect($models)->values();

        Assert::assertNotEmpty(
            $models,
            'assertSelectMatchesPolicy() requires at least one model.'
        );

        $keyName = $models->first()->getKeyName();
        $columnName ??= 'can_' . str_replace('-', '_', $ability);

        $expected = $models
            ->mapWithKeys(fn (Model $model) => [
                $model->getKey() => $gate->allows($ability, $model),
            ])
            ->all();

        $selectQuery = clone $baseQuery;

        $actual = $selectQuery
            ->addSelect([
                $columnName => $gate->select($ability, $selectQuery),
            ])
            ->get()
            ->mapWithKeys(fn (Model $model) => [
                $model->getKey() => (bool) $model->getAttribute($columnName),
            ])
            ->all();

        Assert::assertSame(
            $expected,
            $actual,
            "Policy [{$ability}] and [{$ability}Select] returned different per-row results."
        );
    }
}
