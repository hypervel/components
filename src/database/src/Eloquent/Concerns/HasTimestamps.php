<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Carbon\CarbonInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Attributes\Initialize;
use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Attributes\WithoutTimestamps;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Date;

trait HasTimestamps
{
    /**
     * Context key for storing models that should ignore timestamps.
     */
    protected const IGNORE_TIMESTAMPS_CONTEXT_KEY = '__database.model.ignore_timestamps';

    /**
     * Indicates if the model should be timestamped.
     */
    public bool $timestamps = true;

    /**
     * Initialize the HasTimestamps trait.
     */
    #[Initialize]
    public function initializeHasTimestamps(): void
    {
        if ($this->modelClassAttributesInitialized) {
            return;
        }

        if ($this->timestamps === true) {
            if (static::resolveClassAttribute(WithoutTimestamps::class) !== null) {
                $this->timestamps = false;
            } else {
                /** @var null|Table $table */
                $table = static::resolveClassAttribute(Table::class);

                if ($table && $table->timestamps !== null) {
                    $this->timestamps = $table->timestamps;
                }
            }
        }
    }

    /**
     * Update the model's update timestamp.
     */
    public function touch(array|string|null $attribute = null): bool
    {
        if ($attribute) {
            $time = $this->freshTimestamp();

            foreach (Arr::wrap($attribute) as $column) {
                $this->{$column} = $time;
            }

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
    public function touchQuietly(array|string|null $attribute = null): bool
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
     *
     * @template TReturn
     *
     * @param (callable(): TReturn) $callback
     * @return TReturn
     */
    public static function withoutTimestamps(callable $callback): mixed
    {
        return static::withoutTimestampsOn([static::class], $callback);
    }

    /**
     * Disable timestamps for the given model classes during the given callback scope.
     *
     * @template TReturn
     *
     * @param array<int, class-string> $models
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    public static function withoutTimestampsOn(array $models, callable $callback): mixed
    {
        /** @var list<class-string> $previous */
        $previous = CoroutineContext::get(static::IGNORE_TIMESTAMPS_CONTEXT_KEY, []);
        CoroutineContext::set(static::IGNORE_TIMESTAMPS_CONTEXT_KEY, array_values(array_merge($previous, $models)));

        try {
            return $callback();
        } finally {
            CoroutineContext::set(static::IGNORE_TIMESTAMPS_CONTEXT_KEY, $previous);
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

        /** @var list<class-string> $ignoreTimestampsOn */
        $ignoreTimestampsOn = CoroutineContext::get(static::IGNORE_TIMESTAMPS_CONTEXT_KEY, []);

        foreach ($ignoreTimestampsOn as $ignoredClass) {
            if ($class === $ignoredClass || is_subclass_of($class, $ignoredClass)) {
                return true;
            }
        }

        return false;
    }
}
