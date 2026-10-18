<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;
use Hypervel\Support\ClassMetadataCache;

#[Attribute(Attribute::TARGET_CLASS)]
final class RepairToolCalls
{
    /**
     * Determine whether the target enables tool call repair.
     */
    public static function isAppliedTo(?object $target): bool
    {
        return $target !== null
            && ClassMetadataCache::hasClassAttribute($target, self::class);
    }
}
