<?php

declare(strict_types=1);

namespace Hypervel\Cache;

/**
 * Base for tag sets whose tags namespace the item keyspace.
 *
 * Items are stored under keys derived from the tag set and must be read
 * back through the same tags.
 */
abstract class NamespacedTagSet extends TagSet
{
    /**
     * Get a unique namespace that changes when any of the tags are flushed.
     */
    public function getNamespace(): string
    {
        return implode('|', $this->tagIds());
    }

    /**
     * Get an array of tag identifiers for all of the tags in the set.
     *
     * @return array<string>
     */
    public function tagIds(): array
    {
        return array_map([$this, 'tagId'], $this->names);
    }

    /**
     * Get the unique tag identifier for a given tag.
     */
    abstract public function tagId(string $name): string;

    /**
     * Get the tag identifier key for a given tag.
     */
    abstract public function tagKey(string $name): string;
}
