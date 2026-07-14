<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Support\PermissionPartition;
use LogicException;

class PermissionPartitionViolation extends LogicException
{
    /**
     * Create a new permission partition mismatch exception for a model.
     */
    public static function forModel(Model $model, PermissionPartition $partition, mixed $actual): static
    {
        return new static(__('Model `:model` belongs to permission partition `:actual`, but the current permission partition is `:expected` for column `:column`.', [
            'model' => $model::class,
            'actual' => static::describeValue($actual),
            'expected' => $partition->value,
            'column' => $partition->column,
        ]));
    }

    /**
     * Create a new permission partition mismatch exception for an immutable model partition.
     */
    public static function forImmutablePartition(
        Model $model,
        PermissionPartition $partition,
        mixed $persisted,
        mixed $attempted,
    ): static {
        return new static(__('Permission partition column `:column` is immutable on model `:model`; its persisted value `:persisted` cannot be changed to `:attempted`.', [
            'column' => $partition->column,
            'model' => $model::class,
            'persisted' => static::describeValue($persisted),
            'attempted' => static::describeValue($attempted),
        ]));
    }

    /**
     * Create a new permission partition violation for an invalid persisted value.
     */
    public static function forMissingRecordPartition(Model $model, string $column, mixed $actual): static
    {
        return new static(__('Partitioned model `:model` has no valid persisted value for permission partition column `:column`; received `:actual`.', [
            'model' => $model::class,
            'column' => $column,
            'actual' => static::describeValue($actual),
        ]));
    }

    /**
     * Create a new permission partition mismatch exception for pivot attributes.
     */
    public static function forPivot(PermissionPartition $partition, mixed $actual): static
    {
        return new static(__('Pivot column `:column` cannot use permission partition `:actual`; the captured permission partition is `:expected`.', [
            'column' => $partition->column,
            'actual' => static::describeValue($actual),
            'expected' => $partition->value,
        ]));
    }

    /**
     * Describe a partition value without dumping object state.
     */
    protected static function describeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value === '') {
            return "'' (empty string)";
        }

        return is_int($value) || is_string($value)
            ? (string) $value
            : get_debug_type($value);
    }
}
