<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources;

use Hypervel\Http\Resources\Attributes\PreserveKeys;
use Hypervel\Support\Arr;
use Hypervel\Support\Stringable;
use ReflectionClass;

trait ConditionallyLoadsAttributes
{
    /**
     * The cached preserve keys attribute values.
     *
     * @var array<class-string, bool>
     */
    protected static array $cachedPreserveKeysAttributes = [];

    /**
     * Filter the given data, removing any optional values.
     */
    protected function filter(array $data): array
    {
        $index = -1;

        foreach ($data as $key => $value) {
            ++$index;

            if (is_array($value)) {
                $data[$key] = $this->filter($value);

                continue;
            }

            if (is_numeric($key) && $value instanceof MergeValue) {
                return $this->mergeData(
                    $data,
                    $index,
                    $this->filter($value->data),
                    array_values($value->data) === $value->data
                );
            }

            if ($value instanceof self && is_null($value->resource)) {
                $data[$key] = null;
            }
        }

        return $this->removeMissingValues($data);
    }

    /**
     * Merge the given data in at the given index.
     */
    protected function mergeData(array $data, int $index, array $merge, bool $numericKeys): array
    {
        if ($numericKeys) {
            return $this->removeMissingValues(array_merge(
                array_merge(array_slice($data, 0, $index, true), $merge),
                $this->filter(array_values(array_slice($data, $index + 1, null, true)))
            ));
        }

        return $this->removeMissingValues(array_slice($data, 0, $index, true)
                + $merge
                + $this->filter(array_slice($data, $index + 1, null, true)));
    }

    /**
     * Remove the missing values from the filtered data.
     */
    protected function removeMissingValues(array $data): array
    {
        $numericKeys = true;

        foreach ($data as $key => $value) {
            if (($value instanceof PotentiallyMissing && $value->isMissing())
                || ($value instanceof self
                && $value->resource instanceof PotentiallyMissing
                && $value->isMissing())) { /* @phpstan-ignore method.notFound (delegated to $this->resource via __call) */
                unset($data[$key]);
            } else {
                $numericKeys = $numericKeys && is_numeric($key);
            }
        }

        if (! array_key_exists(static::class, static::$cachedPreserveKeysAttributes)) {
            static::$cachedPreserveKeysAttributes[static::class] = count(
                (new ReflectionClass($this))->getAttributes(PreserveKeys::class)
            ) > 0;
        }

        if (static::$cachedPreserveKeysAttributes[static::class]) {
            return $data;
        }

        if (property_exists($this, 'preserveKeys') && $this->preserveKeys === true) {
            return $data;
        }

        return $numericKeys ? array_values($data) : $data;
    }

    /**
     * Retrieve a value if the given "condition" is truthy.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    protected function when(bool $condition, mixed $value, mixed $default = new MissingValue): mixed
    {
        if ($condition) {
            return value($value);
        }

        return func_num_args() === 3 ? value($default) : $default;
    }

    /**
     * Retrieve a value if the given "condition" is falsy.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    public function unless(bool $condition, mixed $value, mixed $default = new MissingValue): mixed
    {
        $arguments = func_num_args() === 2 ? [$value] : [$value, $default];

        return $this->when(! $condition, ...$arguments);
    }

    /**
     * Merge a value into the array.
     *
     * @return \Hypervel\Http\Resources\MergeValue|mixed
     */
    protected function merge(mixed $value): mixed
    {
        return $this->mergeWhen(true, $value);
    }

    /**
     * Merge a value if the given condition is truthy.
     *
     * @return \Hypervel\Http\Resources\MergeValue|mixed
     */
    protected function mergeWhen(bool $condition, mixed $value, mixed $default = new MissingValue): mixed
    {
        if ($condition) {
            return new MergeValue(value($value));
        }

        return func_num_args() === 3 ? new MergeValue(value($default)) : $default;
    }

    /**
     * Merge a value unless the given condition is truthy.
     *
     * @return \Hypervel\Http\Resources\MergeValue|mixed
     */
    protected function mergeUnless(bool $condition, mixed $value, mixed $default = new MissingValue): mixed
    {
        $arguments = func_num_args() === 2 ? [$value] : [$value, $default];

        return $this->mergeWhen(! $condition, ...$arguments);
    }

    /**
     * Merge the given attributes.
     */
    protected function attributes(array $attributes): MergeValue
    {
        return new MergeValue(
            Arr::only($this->resource->toArray(), $attributes)
        );
    }

    /**
     * Retrieve an attribute if it exists on the resource.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    public function whenHas(string $attribute, mixed $value = null, mixed $default = new MissingValue): mixed
    {
        if (! array_key_exists($attribute, $this->resource->getAttributes())) {
            return value($default);
        }

        return func_num_args() === 1
            ? $this->resource->{$attribute}
            : value($value, $this->resource->{$attribute});
    }

    /**
     * Retrieve a model attribute if it is null.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    protected function whenNull(mixed $value, mixed $default = new MissingValue): mixed
    {
        $arguments = func_num_args() === 1 ? [$value] : [$value, $default];

        return $this->when(is_null($value), ...$arguments);
    }

    /**
     * Retrieve a model attribute if it is not null.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    protected function whenNotNull(mixed $value, mixed $default = new MissingValue): mixed
    {
        $arguments = func_num_args() === 1 ? [$value] : [$value, $default];

        return $this->when(! is_null($value), ...$arguments);
    }

    /**
     * Retrieve an accessor when it has been appended.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    protected function whenAppended(string $attribute, mixed $value = null, mixed $default = new MissingValue): mixed
    {
        if ($this->resource->hasAppended($attribute)) {
            return func_num_args() >= 2 ? value($value) : $this->resource->{$attribute};
        }

        return func_num_args() === 3 ? value($default) : $default;
    }

    /**
     * Retrieve a relationship if it has been loaded.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    protected function whenLoaded(string $relationship, mixed $value = null, mixed $default = new MissingValue): mixed
    {
        if (! $this->resource->relationLoaded($relationship)) {
            return value($default);
        }

        $loadedValue = $this->resource->{$relationship};

        if (func_num_args() === 1) {
            return $loadedValue;
        }

        if ($loadedValue === null) {
            return null;
        }

        if ($value === null) {
            $value = value(...);
        }

        return value($value, $loadedValue);
    }

    /**
     * Retrieve a relationship count if it exists.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    public function whenCounted(string $relationship, mixed $value = null, mixed $default = new MissingValue): mixed
    {
        $attribute = (new Stringable($relationship))->snake()->finish('_count')->value();

        if (! array_key_exists($attribute, $this->resource->getAttributes())) {
            return value($default);
        }

        if (func_num_args() === 1) {
            return $this->resource->{$attribute};
        }

        if ($this->resource->{$attribute} === null) {
            return null;
        }

        if ($value === null) {
            $value = value(...);
        }

        return value($value, $this->resource->{$attribute});
    }

    /**
     * Retrieve a relationship aggregated value if it exists.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    public function whenAggregated(string $relationship, string $column, string $aggregate, mixed $value = null, mixed $default = new MissingValue): mixed
    {
        $attribute = (new Stringable($relationship))->snake()->append('_')->append($aggregate)->append('_')->finish($column)->value();

        if (! array_key_exists($attribute, $this->resource->getAttributes())) {
            return value($default);
        }

        if (func_num_args() === 3) {
            return $this->resource->{$attribute};
        }

        if ($this->resource->{$attribute} === null) {
            return null;
        }

        if ($value === null) {
            $value = value(...);
        }

        return value($value, $this->resource->{$attribute});
    }

    /**
     * Retrieve a relationship existence check if it exists.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    public function whenExistsLoaded(string $relationship, mixed $value = null, mixed $default = new MissingValue): mixed
    {
        $attribute = (new Stringable($relationship))->snake()->finish('_exists')->value();

        if (! array_key_exists($attribute, $this->resource->getAttributes())) {
            return value($default);
        }

        if (func_num_args() === 1) {
            return $this->resource->{$attribute};
        }

        if ($this->resource->{$attribute} === null) {
            return null;
        }

        return value($value, $this->resource->{$attribute});
    }

    /**
     * Execute a callback if the given pivot table has been loaded.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    protected function whenPivotLoaded(string $table, mixed $value, mixed $default = new MissingValue): mixed
    {
        return $this->whenPivotLoadedAs('pivot', ...func_get_args());
    }

    /**
     * Execute a callback if the given pivot table with a custom accessor has been loaded.
     *
     * @return \Hypervel\Http\Resources\MissingValue|mixed
     */
    protected function whenPivotLoadedAs(string $accessor, string $table, mixed $value, mixed $default = new MissingValue): mixed
    {
        return $this->when(
            $this->hasPivotLoadedAs($accessor, $table),
            $value,
            $default,
        );
    }

    /**
     * Determine if the resource has the specified pivot table loaded.
     */
    protected function hasPivotLoaded(string $table): bool
    {
        return $this->hasPivotLoadedAs('pivot', $table);
    }

    /**
     * Determine if the resource has the specified pivot table loaded with a custom accessor.
     */
    protected function hasPivotLoadedAs(string $accessor, string $table): bool
    {
        return isset($this->resource->{$accessor})
            && ($this->resource->{$accessor} instanceof $table
                || $this->resource->{$accessor}->getTable() === $table);
    }

    /**
     * Transform the given value if it is present.
     */
    protected function transform(mixed $value, callable $callback, mixed $default = new MissingValue): mixed
    {
        return transform(
            $value,
            $callback,
            $default
        );
    }
}
