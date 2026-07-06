<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Exceptions\NotSupportedException;
use Hypervel\Contracts\Cache\Store;

abstract class TaggableStore implements Store
{
    /**
     * Begin executing a new tags operation.
     *
     * @throws NotSupportedException when conditional tag support is unavailable
     */
    public function tags(mixed $names): TaggedCache
    {
        return new NamespacedTaggedCache($this, new VersionedTagSet($this, is_array($names) ? $names : func_get_args()));
    }

    /**
     * Determine if this store currently supports tags.
     *
     * Stores whose tag support depends on configuration or composition
     * override this; for everything else extending TaggableStore, tag
     * support is unconditional. A store that can return false here must
     * also throw a NotSupportedException from tags() because Repository
     * relies on the store to enforce its own conditional support.
     */
    public function supportsTags(): bool
    {
        return true;
    }

    /**
     * Get the tag mode this store operates under.
     *
     * Default matches TaggableStore::tags() semantics (all-mode: keys are
     * namespaced by the tag set). Subclasses override if they deviate.
     */
    public function getTagMode(): TagMode
    {
        return TagMode::All;
    }
}
