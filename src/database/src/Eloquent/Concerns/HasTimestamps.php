<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Carbon\CarbonInterface;
use Hypervel\Support\Facades\Date;

trait HasTimestamps
{
    /**
     * Indicates if the model should be timestamped.
     */
    public bool $timestamps = true;

    /**
     * The list of models classes that have timestamps temporarily disabled.
     *
     * @var array<int, class-string>
     */
    protected static array $ignoreTimestampsOn = [];

    /**
     * Update the model's update timestamp.
     */
    public function touch(?string $attribute = null): bool
    {
        if ($attribute) {
            $this->{$attribute} = $this->freshTimestamp();

            return $this->save();
        }

        if (! $this->usesTimestamps()) {
            return false;
        }

        $this->updateTimestamps();

        return $this->save();
    }

    /**
     * Update the model's update timestamp without raising any events.
     */
    public function touchQuietly(?string $attribute = null): bool
    {
        return static::withoutEvents(fn () => $this->touch($attribute));
    }

    /**
     * Update the creation and update timestamps.
     *
     * @return $this
     */
    public function updateTimestamps(): static
    {
        $time = $this->freshTimestamp();

        $updatedAtColumn = $this->getUpdatedAtColumn();

        if (! is_null($updatedAtColumn) && ! $this->isDirty($updatedAtColumn)) {
            $this->setUpdatedAt($time);
        }

        $createdAtColumn = $this->getCreatedAtColumn();

        if (! $this->exists && ! is_null($createdAtColumn) && ! $this->isDirty($createdAtColumn)) {
            $this->setCreatedAt($time);
        }

        return $this;
    }

    /**
     * Set the value of the "created at" attribute.
     *
     * @return $this
     */
    public function setCreatedAt(mixed $value): static
    {
        $this->{$this->getCreatedAtColumn()} = $value;

        return $this;
    }

    /**
     * Set the value of the "updated at" attribute.
     *
     * @return $this
     */
    public function setUpdatedAt(mixed $value): static
    {
        $this->{$this->getUpdatedAtColumn()} = $value;

        return $this;
    }

    /**
     * Get a fresh timestamp for the model.
     */
    public function freshTimestamp(): CarbonInterface
    {
        return Date::now();
    }

    /**
     * Get a fresh timestamp for the model.
     */
    public function freshTimestampString(): string
    {
        return $this->fromDateTime($this->freshTimestamp());
    }

    /**
     * Determine if the model uses timestamps.
     */
    public function usesTimestamps(): bool
    {
        return $this->timestamps && ! static::isIgnoringTimestamps($this::class);
    }

    /**
     * Get the name of the "created at" column.
     */
    public function getCreatedAtColumn(): ?string
    {
        return static::CREATED_AT;
    }

    /**
     * Get the name of the "updated at" column.
     */
    public function getUpdatedAtColumn(): ?string
    {
        return static::UPDATED_AT;
    }

    /**
     * Get the fully qualified "created at" column.
     */
    public function getQualifiedCreatedAtColumn(): ?string
    {
        $column = $this->getCreatedAtColumn();

        return $column ? $this->qualifyColumn($column) : null;
    }

    /**
     * Get the fully qualified "updated at" column.
     */
    public function getQualifiedUpdatedAtColumn(): ?string
    {
        $column = $this->getUpdatedAtColumn();

        return $column ? $this->qualifyColumn($column) : null;
    }

    /**
     * Disable timestamps for the current class during the given callback scope.
     */
    public static function withoutTimestamps(callable $callback): mixed
    {
        return static::withoutTimestampsOn([static::class], $callback);
    }

    /**
     * Disable timestamps for the given model classes during the given callback scope.
     *
     * @param array<int, class-string> $models
     */
    public static function withoutTimestampsOn(array $models, callable $callback): mixed
    {
        // @phpstan-ignore arrayValues.list (unset() in finally block creates gaps, array_values re-indexes)
        static::$ignoreTimestampsOn = array_values(array_merge(static::$ignoreTimestampsOn, $models));

        try {
            return $callback();
        } finally {
            foreach ($models as $model) {
                if (($key = array_search($model, static::$ignoreTimestampsOn, true)) !== false) {
                    unset(static::$ignoreTimestampsOn[$key]);
                }
            }
        }
    }

    /**
     * Determine if the given model is ignoring timestamps / touches.
     *
     * @param null|class-string $class
     */
    public static function isIgnoringTimestamps(?string $class = null): bool
    {
        $class ??= static::class;

        foreach (static::$ignoreTimestampsOn as $ignoredClass) {
            if ($class === $ignoredClass || is_subclass_of($class, $ignoredClass)) {
                return true;
            }
        }

        return false;
    }
}
