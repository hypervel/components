<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

/**
 * Tagged cache for namespaced tag semantics.
 *
 * Item keys are namespaced by the tag set: values must be read, written,
 * and deleted through the same ordered tag set used to store them.
 */
class NamespacedTaggedCache extends TaggedCache
{
    /**
     * The tag set instance.
     *
     * @var NamespacedTagSet
     */
    protected TagSet $tags;

    /**
     * Create a new tagged cache instance.
     */
    public function __construct(Store $store, NamespacedTagSet $tags)
    {
        parent::__construct($store, $tags);
    }

    /**
     * Get a fully qualified key for a tagged item.
     */
    public function taggedItemKey(string $key): string
    {
        return hash('xxh128', $this->tags->getNamespace()) . ':' . $key;
    }

    /**
     * Get the tag set instance.
     */
    public function getTags(): NamespacedTagSet
    {
        return $this->tags;
    }

    /**
     * Format the key for a cache item.
     */
    protected function itemKey(string $key): string
    {
        return $this->taggedItemKey($key);
    }
}
