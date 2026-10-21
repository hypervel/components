<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Enums\CacheScope;
use Hypervel\Mcp\Server\Attributes\Cacheable;
use Hypervel\Mcp\Server\Resource;

#[Cacheable(ttlMs: 120000, scope: CacheScope::Public)]
class CacheableResource extends Resource
{
    /**
     * Describe the cacheable resource.
     */
    public function description(): string
    {
        return 'A resource that declares its own caching hint';
    }

    /**
     * Return cacheable content.
     */
    public function handle(): string
    {
        return 'Cacheable contents.';
    }
}
