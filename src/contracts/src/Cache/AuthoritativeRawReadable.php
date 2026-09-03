<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cache;

use UnitEnum;

/**
 * Capability interface for cache wrappers that can bypass non-authoritative read layers.
 *
 * @internal Used by cache coordination. Application code should read through
 *   the cache repository's public API.
 */
interface AuthoritativeRawReadable
{
    /**
     * Retrieve an item without serving it from a non-authoritative read layer.
     */
    public function getAuthoritativeRaw(UnitEnum|string $key): mixed;
}
