<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

class TagSet
{
    /**
     * The cache store implementation.
     */
    protected Store $store;

    /**
     * The tag names.
     */
    protected array $names = [];

    /**
     * Create a new TagSet instance.
     */
    public function __construct(Store $store, array $names = [])
    {
        $this->store = $store;
        $this->names = $names;
    }

    /**
     * Reset all tags in the set.
     */
    public function reset()
    {
        array_walk($this->names, [$this, 'resetTag']);
    }

    /**
     * Reset the tag and return the new tag identifier.
     */
    public function resetTag(string $name): string
    {
        $this->store->forever($this->tagKey($name), $id = str_replace('.', '', uniqid('', true)));

        return $id;
    }

    /**
     * Flush all the tags in the set.
     */
    public function flush(): void
    {
        array_walk($this->names, [$this, 'flushTag']);
    }

    /**
     * Flush the tag from the cache.
     */
    public function flushTag(string $name): string
    {
        $this->store->forget($key = $this->tagKey($name));

        return $key;
    }

    /**
     * Get a unique namespace that changes when any of the tags are flushed.
     */
    public function getNamespace(): string
    {
        return implode('|', $this->tagIds());
    }

    /**
     * Get the unique tag identifier for a given tag.
     */
    public function tagId(string $name): string
    {
        return $this->store->get($this->tagKey($name)) ?: $this->resetTag($name);
    }

    /**
     * Get the tag identifier key for a given tag.
     */
    public function tagKey(string $name): string
    {
        return 'tag:' . $name . ':key';
    }

    /**
     * Get all of the tag names in the set.
     */
    public function getNames(): array
    {
        return $this->names;
    }

    /**
     * Get an array of tag identifiers for all of the tags in the set.
     */
    protected function tagIds(): array
    {
        return array_map([$this, 'tagId'], $this->names);
    }
}
