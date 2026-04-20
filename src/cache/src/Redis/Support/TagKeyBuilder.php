<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Support;

use Hypervel\Cache\TagMode;

/**
 * Builds Redis-internal tag storage key names.
 *
 * The key formats here are Redis implementation details that were
 * previously methods on the TagMode enum. Moved out so the enum stays
 * purely semantic.
 */
class TagKeyBuilder
{
    public function __construct(
        private readonly TagMode $mode,
        private readonly string $prefix,
    ) {
    }

    /**
     * Mode-only tag segment, for callers that don't have a prefix to hand
     * (e.g. console tools that concatenate a cachePrefix externally).
     */
    public static function tagSegmentFor(TagMode $mode): string
    {
        return "_{$mode->value}:tag:";
    }

    /**
     * Tag segment prefix: "_any:tag:" or "_all:tag:".
     */
    public function tagSegment(): string
    {
        return "_{$this->mode->value}:tag:";
    }

    /**
     * Tag identifier (without cache prefix): "_any:tag:{tagName}:entries".
     */
    public function tagId(string $tagName): string
    {
        return $this->tagSegment() . $tagName . ':entries';
    }

    /**
     * Full tag key with cache prefix: "{prefix}_any:tag:{tagName}:entries".
     */
    public function tagKey(string $tagName): string
    {
        return $this->prefix . $this->tagId($tagName);
    }

    /**
     * Full reverse index key: "{prefix}{cacheKey}:_any:tags".
     *
     * Any mode uses this to track which tags a cache key belongs to.
     */
    public function reverseIndexKey(string $cacheKey): string
    {
        return $this->prefix . $cacheKey . ":_{$this->mode->value}:tags";
    }

    /**
     * Full registry key: "{prefix}_any:tag:registry".
     *
     * Any mode uses this as a sorted set of all active tags.
     */
    public function registryKey(): string
    {
        return $this->prefix . $this->tagSegment() . 'registry';
    }
}
