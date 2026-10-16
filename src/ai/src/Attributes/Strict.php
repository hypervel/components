<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;
use Hypervel\Support\ClassMetadataCache;

#[Attribute(Attribute::TARGET_CLASS)]
final class Strict
{
    /**
     * Determine whether the target requires strict schema output.
     */
    public static function isAppliedTo(?object $target): bool
    {
        return $target !== null
            && ClassMetadataCache::hasClassAttribute($target, self::class);
    }
}
