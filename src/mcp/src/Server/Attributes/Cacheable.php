<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Attributes;

use Attribute;
use Hypervel\Mcp\Enums\CacheScope;

#[Attribute(Attribute::TARGET_CLASS)]
class Cacheable
{
    /**
     * Create cache settings for a resource.
     */
    public function __construct(
        public int $ttlMs = 0,
        public CacheScope $scope = CacheScope::Private,
    ) {
    }

    /**
     * Convert the cache settings to protocol hints.
     *
     * @return array{ttlMs: int, cacheScope: string}
     */
    public function toArray(): array
    {
        return [
            'ttlMs' => max(0, $this->ttlMs),
            'cacheScope' => $this->scope->value,
        ];
    }
}
