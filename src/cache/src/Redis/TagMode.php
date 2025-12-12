<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis;

/**
 * Tag mode enum - single source of truth for all mode-specific configuration.
 *
 * Any mode: Items are retrievable without specifying tags. Flushing ANY matching tag removes the item.
 * All mode: Items must be retrieved with the same tags used when storing. Items match ALL specified tags.
 */
enum TagMode: string
{
    case Any = 'any';
    case All = 'all';

    /**
     * Create from config value with fallback to All.
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom($value) ?? self::All;
    }

    /**
     * Tag segment prefix: "_any:tag:" or "_all:tag:"
     */
    public function tagSegment(): string
    {
        return "_{$this->value}:tag:";
    }

    /**
     * Tag identifier (without cache prefix): "_any:tag:{tagName}:entries"
     *
     * Used by All mode for namespace computation (sha1 of sorted tag IDs).
     */
    public function tagId(string $tagName): string
    {
        return $this->tagSegment() . $tagName . ':entries';
    }

    /**
     * Full tag key (with cache prefix): "{prefix}_any:tag:{tagName}:entries"
     */
    public function tagKey(string $prefix, string $tagName): string
    {
        return $prefix . $this->tagId($tagName);
    }

    /**
     * Reverse index suffix: ":_any:tags"
     */
    public function reverseIndexSuffix(): string
    {
        return ":_{$this->value}:tags";
    }

    /**
     * Full reverse index key: "{prefix}{cacheKey}:_any:tags"
     *
     * Tracks which tags a cache key belongs to (Any mode only).
     */
    public function reverseIndexKey(string $prefix, string $cacheKey): string
    {
        return $prefix . $cacheKey . $this->reverseIndexSuffix();
    }

    /**
     * Registry key: "{prefix}_any:tag:registry"
     *
     * Sorted set tracking all active tags (Any mode only).
     */
    public function registryKey(string $prefix): string
    {
        return $prefix . $this->tagSegment() . 'registry';
    }

    /**
     * Check if this is Any mode.
     */
    public function isAnyMode(): bool
    {
        return $this === self::Any;
    }

    /**
     * Check if this is All mode.
     */
    public function isAllMode(): bool
    {
        return $this === self::All;
    }

    /**
     * Any mode: items retrievable without specifying tags.
     * All mode: must specify same tags used when storing.
     */
    public function supportsDirectGet(): bool
    {
        return $this->isAnyMode();
    }

    /**
     * All mode: keys are namespaced with sha1 of tag names.
     */
    public function usesNamespacedKeys(): bool
    {
        return $this->isAllMode();
    }

    /**
     * Any mode has reverse index tracking which tags a key belongs to.
     */
    public function hasReverseIndex(): bool
    {
        return $this->isAnyMode();
    }

    /**
     * Any mode has registry tracking all active tags.
     */
    public function hasRegistry(): bool
    {
        return $this->isAnyMode();
    }
}
