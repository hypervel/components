<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use DateTimeInterface;
use Hypervel\Context\Context;
use Hypervel\Database\Eloquent\Model;

class NodeContext
{
    public static function keepDeletedAt(Model $model): void
    {
        Context::set(
            '__nested_set.deleted_at.' . get_class($model),
            $model->{$model->getDeletedAtColumn()} // @phpstan-ignore-line
        );
    }

    public static function restoreDeletedAt(Model $model): DateTimeInterface|int|string
    {
        $deletedAt = Context::get('__nested_set.deleted_at.' . get_class($model));

        if (! is_null($deletedAt)) {
            /* @phpstan-ignore-next-line */
            $model->{$model->getDeletedAtColumn()} = $deletedAt;
        }

        return $deletedAt;
    }

    public static function hasPerformed(Model $model): bool
    {
        return Context::get('__nested_set.has_performed.' . get_class($model), false);
    }

    public static function setHasPerformed(Model $model, bool $performed = true): void
    {
        Context::set('__nested_set.has_performed.' . get_class($model), $performed);
    }
}
