<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

interface PropertyMorphableData
{
    /**
     * Resolve the concrete data class for the given properties.
     *
     * @return null|class-string<static>
     */
    public static function morph(array $properties): ?string;
}
