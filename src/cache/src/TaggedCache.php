<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Contracts\Store;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushing;

class TaggedCache extends Repository
{
    use RetrievesMultipleKeys {
        putMany as putManyAlias;
    }

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

        return $this->putManyAlias($values, $ttl);
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): bool|int
    {
        return $this->store->increment($this->itemKey($key), $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): bool|int
    {
        return $this->store->decrement($this->itemKey($key), $value);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->event(new CacheFlushing($this->getName()));

        $this->tags->reset();

        $this->event(new CacheFlushed($this->getName()));

        return true;
    }

    /**
     * Get a fully qualified key for a tagged item.
     */
    public function taggedItemKey(string $key): string
    {
        return sha1($this->tags->getNamespace()) . ':' . $key;
    }

    /**
     * Get the tag set instance.
     */
    public function getTags(): TagSet
    {
        return $this->tags;
    }

    protected function itemKey(string $key): string
    {
        return $this->taggedItemKey($key);
    }

    /**
     * Fire an event for this cache instance.
     */
    protected function event(object $event): void
    {
        parent::event($event->setTags($this->tags->getNames()));
    }
}
