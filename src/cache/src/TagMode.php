<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use InvalidArgumentException;

/**
 * Tag mode enum - describes the semantic of how tags participate in a
 * taggable store's behavior.
 *
 * All mode: items are stored in a tag-namespaced keyspace and must be
 * retrieved with the same tag set used when writing (Laravel's classic
 * behavior).
 *
 * Any mode: items live under plain keys independent of tags; tags serve
 * as a parallel index used only for bulk operations. Items are
 * retrievable without specifying any tags, and flushing ANY one of a
 * key's tags removes the item.
 */
enum TagMode: string
{
    case Any = 'any';
    case All = 'all';

    /**
     * Create from a config string.
     *
     * @throws InvalidArgumentException
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(
            sprintf(
                'Invalid cache tag mode [%s]. Supported modes are [%s].',
                $value,
                implode(', ', self::supportedValues()),
            )
        );
    }

    /**
     * Get the supported configuration values.
     *
     * @return list<string>
     */
    public static function supportedValues(): array
    {
        return array_map(
            static fn (self $mode): string => $mode->value,
            self::cases(),
        );
    }

    /**
     * Determine if this is any mode.
     */
    public function isAnyMode(): bool
    {
        return $this === self::Any;
    }

    /**
     * Determine if this is all mode.
     */
    public function isAllMode(): bool
    {
        return $this === self::All;
    }

    /**
     * Whether stores in this mode permit direct reads by the plain key,
     * without re-applying the tag set used to write the entry.
     *
     * Any mode: yes — tags don't namespace the key.
     * All mode: no — the key is namespaced by the tag set.
     */
    public function supportsDirectGet(): bool
    {
        return $this === self::Any;
    }
}
