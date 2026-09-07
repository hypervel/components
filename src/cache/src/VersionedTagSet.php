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
    public function reset(): bool
    {
        return $this->attemptEach(
            $this->names,
            fn (string $name): bool => $this->writeTagId($name, $this->newTagId()),
        );
    }

    /**
     * Reset the tag and return the new tag identifier.
     */
    public function resetTag(string $name): string
    {
        $id = $this->newTagId();

        $this->writeTagId($name, $id);

        return $id;
    }

    /**
     * Flush all the tags in the set.
     */
    public function flush(): bool
    {
        return $this->attemptEach(
            $this->names,
            function (string $name): bool {
                $this->flushTag($name);

                return true;
            },
        );
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

    /**
     * Generate a new tag identifier.
     */
    protected function newTagId(): string
    {
        return str_replace('.', '', uniqid('', true));
    }

    /**
     * Persist a tag identifier.
     */
    protected function writeTagId(string $name, string $id): bool
    {
        return $this->store->forever($this->tagKey($name), $id);
    }
}
