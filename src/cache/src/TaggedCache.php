<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Contracts\Cache\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

abstract class TaggedCache extends Repository
{
    /**
     * The tag set instance.
     */
    protected TagSet $tags;

    /**
     * Create a new tagged cache instance.
     */
    public function __construct(Store $store, TagSet $tags)
    {
        parent::__construct($store);

        $this->tags = $tags;
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->putManyForever($values);
        }

        $result = true;

        foreach ($values as $key => $value) {
            $result = $this->put((string) $key, $value, $ttl) && $result;
        }

        return $result;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        return $this->store->increment($this->itemKey($key), $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        return $this->store->decrement($this->itemKey($key), $value);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->event(CacheFlushing::class, fn (): CacheFlushing => new CacheFlushing($this->getName()));

        $this->tags->reset();

        $this->event(CacheFlushed::class, fn (): CacheFlushed => new CacheFlushed($this->getName()));

        return true;
    }

    /**
     * Remove all items from the cache.
     *
     * A tagged cache's PSR clear() scope is the tag set, not the whole store.
     */
    public function clear(): bool
    {
        return $this->flush();
    }

    /**
     * Get the tag set instance.
     */
    public function getTags(): TagSet
    {
        return $this->tags;
    }

    /**
     * Fire an event for this cache instance.
     */
    protected function event(string $eventClass, Closure $event): void
    {
        parent::event($eventClass, function () use ($event): object {
            $resolvedEvent = $event();

            if (method_exists($resolvedEvent, 'setTags')) {
                $resolvedEvent->setTags($this->tags->getNames());
            }

            return $resolvedEvent;
        });
    }
}
