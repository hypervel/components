<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use DateTimeInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Model;

class NodeContext
{
    /**
     * Context key prefix for preserved deleted_at timestamps.
     */
    protected const DELETED_AT_CONTEXT_PREFIX = '__nested_set.deleted_at.';

    /**
     * Context key prefix for tracking whether a node operation has been performed.
     */
    protected const HAS_PERFORMED_CONTEXT_PREFIX = '__nested_set.has_performed.';

    public static function keepDeletedAt(Model $model): void
    {
        CoroutineContext::set(
            self::DELETED_AT_CONTEXT_PREFIX . get_class($model),
            $model->{$model->getDeletedAtColumn()} // @phpstan-ignore-line
        );
    }

    public static function restoreDeletedAt(Model $model): DateTimeInterface|int|string
    {
        $deletedAt = CoroutineContext::get(self::DELETED_AT_CONTEXT_PREFIX . get_class($model));

        if (! is_null($deletedAt)) {
            /* @phpstan-ignore-next-line */
            $model->{$model->getDeletedAtColumn()} = $deletedAt;
        }

        return $deletedAt;
    }

    public static function hasPerformed(Model $model): bool
    {
        return CoroutineContext::get(self::HAS_PERFORMED_CONTEXT_PREFIX . get_class($model), false);
    }

    public static function setHasPerformed(Model $model, bool $performed = true): void
    {
        CoroutineContext::set(self::HAS_PERFORMED_CONTEXT_PREFIX . get_class($model), $performed);
    }
}
