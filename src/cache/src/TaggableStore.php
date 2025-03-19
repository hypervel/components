<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Contracts\Store;

abstract class TaggableStore implements Store
{
    /**
     * Begin executing a new tags operation.
     */
    public function tags(mixed $names): TaggedCache
    {
        return new TaggedCache($this, new TagSet($this, is_array($names) ? $names : func_get_args()));
    }
}
