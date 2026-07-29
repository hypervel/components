<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Model;

class NodeContext
{
    /**
     * Context key prefix for tracking whether a node operation has been performed.
     */
    protected const HAS_PERFORMED_CONTEXT_KEY_PREFIX = '__nested_set.has_performed.';

    /**
     * Determine whether the logical tree has changed in this coroutine.
     */
    public static function hasPerformed(Model $model): bool
    {
        return CoroutineContext::get(static::performedKey($model), false);
    }

    /**
     * Mark that the logical tree has changed in this coroutine.
     */
    public static function setHasPerformed(Model $model): void
    {
        CoroutineContext::set(static::performedKey($model), true);
    }

    /**
     * Get the model's logical nested set table identity.
     */
    public static function structuralIdentity(Model $model): string
    {
        $connection = $model->getConnection();
        $name = $connection->getName();

        if ($name === null || $name === '') {
            $name = $model::getConnectionResolver()?->getDefaultConnection();
        }

        if ($name === null || $name === '') {
            $name = 'default';
        }

        return strlen($name) . ':' . $name . ':' . $model->getTable();
    }

    /**
     * Get the structural freshness key for the model's logical table.
     */
    protected static function performedKey(Model $model): string
    {
        return self::HAS_PERFORMED_CONTEXT_KEY_PREFIX . static::structuralIdentity($model);
    }
}
