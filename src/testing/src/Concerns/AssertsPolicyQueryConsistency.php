<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Assert that query-aware policy results match per-model Gate checks.
 *
 * These assertions use the application's Gate captured when the query-builder
 * macros were booted. Register policies on that Gate instead of replacing its
 * container binding after the provider has booted.
 */
trait AssertsPolicyQueryConsistency
{
    /**
     * Assert that query filtering matches per-model policy results.
     *
     * @param iterable<Model> $models
     */
    protected function assertWhereCanMatchesPolicy(
        UnitEnum|string $ability,
        Builder $baseQuery,
        iterable $models,
        mixed $user = null,
    ): void {
        $gate = $this->app->make(Gate::class);
        $queryGate = $user === null ? $gate : $gate->forUser($user);
        $abilityName = (string) enum_value($ability);

        $models = collect($models)->values();

        Assert::assertNotEmpty(
            $models,
            'assertWhereCanMatchesPolicy() requires at least one model.'
        );

        $keyName = $models->first()->getKeyName();

        $expectedIds = $models
            ->filter(fn (Model $model) => $queryGate->allows($ability, $model))
            ->pluck($keyName)
            ->sort()
            ->values()
            ->all();

        $query = clone $baseQuery;

        $actualIds = $query
            ->whereCan($ability, $user)
            ->pluck($keyName)
            ->sort()
            ->values()
            ->all();

        Assert::assertSame(
            $expectedIds,
            $actualIds,
            "Policy [{$abilityName}] and whereCan() returned different row sets."
        );
    }

    /**
     * Assert that query annotations match per-model policy results.
     *
     * @param iterable<Model> $models
     */
    protected function assertWithCanMatchesPolicy(
        UnitEnum|string $ability,
        Builder $baseQuery,
        iterable $models,
        mixed $user = null,
        ?string $columnName = null,
    ): void {
        $gate = $this->app->make(Gate::class);
        $queryGate = $user === null ? $gate : $gate->forUser($user);
        $abilityName = (string) enum_value($ability);

        $models = collect($models)->values();

        Assert::assertNotEmpty(
            $models,
            'assertWithCanMatchesPolicy() requires at least one model.'
        );

        $columnName ??= 'hypervel_policy_result';

        $expected = $models
            ->mapWithKeys(fn (Model $model) => [
                $model->getKey() => $queryGate->allows($ability, $model),
            ])
            ->all();

        $query = clone $baseQuery;

        $actual = $query
            ->withCan($abilityName . ' as ' . $columnName, $user)
            ->get()
            ->mapWithKeys(fn (Model $model) => [
                $model->getKey() => $model->getAttribute($columnName),
            ])
            ->all();

        Assert::assertSame(
            $expected,
            $actual,
            "Policy [{$abilityName}] and withCan() returned different per-row results."
        );
    }
}
