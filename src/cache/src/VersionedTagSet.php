<?php

declare(strict_types=1);

namespace Hypervel\Cache;

/**
 * Generic namespaced tag set for stores without a native tag index.
 *
 * Each tag's identifier is a random value stored in the cache itself;
 * resetting a tag rotates the identifier, which changes the namespace and
 * lets previously tagged entries expire naturally.
 */
class VersionedTagSet extends NamespacedTagSet
{
    /**
     * Reset all tags in the set.
     */
    public function reset(): void
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
}
