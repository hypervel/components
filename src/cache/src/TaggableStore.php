<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

abstract class TaggableStore implements Store
{
    /**
     * Begin executing a new tags operation.
     */
    public function tags(mixed $names): TaggedCache
    {
        return new TaggedCache($this, new TagSet($this, is_array($names) ? $names : func_get_args()));
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
