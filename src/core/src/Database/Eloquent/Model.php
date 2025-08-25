<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\DbConnection\Model\Model as BaseModel;
use Hyperf\Stringable\Str;
use Hypervel\Broadcasting\Contracts\HasBroadcastChannel;
use Hypervel\Context\Context;
use Hypervel\Database\Eloquent\Concerns\HasCallbacks;
use Hypervel\Database\Eloquent\Concerns\HasObservers;
use Hypervel\Database\Eloquent\Concerns\HasRelations;
use Hypervel\Router\Contracts\UrlRoutable;
use Psr\EventDispatcher\EventDispatcherInterface;

abstract class Model extends BaseModel implements UrlRoutable, HasBroadcastChannel
{
    use HasCallbacks;
    use HasRelations;
    use HasObservers;

    protected ?string $connection = null;

    public function resolveRouteBinding($value)
    {
        /* @phpstan-ignore-next-line */
        return $this->where($this->getRouteKeyName(), $value)->firstOrFail();
    }

    /**
     * Create a new Model Collection instance.
     */
    public function newCollection(array $models = [])
    {
        return new Collection($models);
    }

    /**
     * Get the broadcast channel route definition that is associated with the given entity.
     */
    public function broadcastChannelRoute(): string
    {
        return str_replace('\\', '.', get_class($this)) . '.{' . Str::camel(class_basename($this)) . '}';
    }

    /**
     * Get the broadcast channel name that is associated with the given entity.
     */
    public function broadcastChannel(): string
    {
        return str_replace('\\', '.', get_class($this)) . '.' . $this->getKey();
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        if (Context::get($this->getWithoutEventContextKey())) {
            return null;
        }

        return parent::getEventDispatcher();
    }

    /**
     * Execute a callback without firing any model events for any model type.
     */
    public static function withoutEvents(callable $callback): mixed
    {
        $key = static::getWithoutEventContextKey();
        $depth = Context::get($key) ?? 0;
        Context::set($key, $depth + 1);

        try {
            return $callback();
        } finally {
            $depth = Context::get($key) ?? 1;
            if ($depth <= 1) {
                Context::destroy($key);
            } else {
                Context::set($key, $depth - 1);
            }
        }
    }

    /**
     * Save the model and all of its relationships without raising any events to the parent model.
     */
    public function pushQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->push());
    }

    /**
     * Save the model to the database without raising any events.
     */
    public function saveQuietly(array $options = []): bool
    {
        return static::withoutEvents(fn () => $this->save($options));
    }

    /**
     * Update the model in the database without raising any events.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->saveQuietly($options);
    }

    /**
     * Increment a column's value by a given amount without raising any events.
     * @param mixed $amount
     */
    public function incrementQuietly(string $column, $amount = 1, array $extra = []): int
    {
        return static::withoutEvents(
            fn () => $this->incrementOrDecrement($column, $amount, $extra, 'increment')
        );
    }

    /**
     * Decrement a column's value by a given amount without raising any events.
     */
    public function decrementQuietly(string $column, float|int $amount = 1, array $extra = []): int
    {
        return static::withoutEvents(
            fn () => $this->incrementOrDecrement($column, $amount, $extra, 'decrement')
        );
    }

    /**
     * Delete the model from the database without raising any events.
     */
    public function deleteQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->delete());
    }

    /**
     * Clone the model into a new, non-existing instance without raising any events.
     */
    public function replicateQuietly(?array $except = null): static
    {
        return static::withoutEvents(fn () => $this->replicate($except));
    }

    protected static function getWithoutEventContextKey(): string
    {
        return '__database.model.without_events.' . static::class;
    }
}
