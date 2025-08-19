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
        Context::set(self::getWithoutEventContextKey(), true);

        try {
            return $callback();
        } finally {
            Context::destroy(self::getWithoutEventContextKey());
        }
    }

    protected static function getWithoutEventContextKey(): string
    {
        return '__database.model.without_events.' . static::class;
    }
}
