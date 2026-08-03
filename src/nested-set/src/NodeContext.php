<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Eloquent\Model;

class NodeContext
{
    /**
     * Context key prefix for structural freshness state.
     */
    protected const FRESHNESS_CONTEXT_KEY_PREFIX = '__nested_set.freshness.';

    /**
     * Determine whether the model is current for the logical tree revision.
     */
    public static function isCurrent(Model $model): bool
    {
        $freshness = static::freshness($model);

        return $freshness === null || $freshness->isCurrent($model);
    }

    /**
     * Record the model at the current logical tree revision.
     */
    public static function markCurrent(Model $model): void
    {
        static::freshness($model)?->observe($model);
    }

    /**
     * Mark that the logical tree has changed in this coroutine.
     */
    public static function markTreeChanged(Model $model): void
    {
        /** @var NodeFreshness $freshness */
        $freshness = CoroutineContext::getOrSet(
            static::freshnessKey($model),
            static fn () => new NodeFreshness,
        );

        $freshness->advance();
    }

    /**
     * Get the model's logical nested set table identity.
     */
    public static function structuralIdentity(Model $model): string
    {
        $name = $model->getConnectionName();

        if ($name === null || $name === '') {
            $name = $model::getConnectionResolver()?->getDefaultConnection();
        }

        if ($name === null || $name === '') {
            $name = 'default';
        }

        $name = ConnectionName::parse($name)->base;

        return strlen($name) . ':' . $name . ':' . $model->getTable();
    }

    /**
     * Get the structural freshness state for the model's logical table.
     */
    protected static function freshness(Model $model): ?NodeFreshness
    {
        /** @var ?NodeFreshness */
        return CoroutineContext::get(static::freshnessKey($model));
    }

    /**
     * Get the structural freshness key for the model's logical table.
     */
    protected static function freshnessKey(Model $model): string
    {
        return self::FRESHNESS_CONTEXT_KEY_PREFIX . static::structuralIdentity($model);
    }
}
