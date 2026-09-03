<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

interface EmptyData
{
    /**
     * Create an empty representation of the data object.
     */
    public static function empty(
        array $extra = [],
        mixed $replaceNullValuesWith = null,
        ?array $except = null,
        ?array $only = null,
    ): array;
}
