<?php

declare(strict_types=1);

namespace Hypervel\Validation;

interface PresenceVerifierInterface
{
    /**
     * Count the number of objects in a collection having the given value.
     */
    public function getCount(string $collection, string $column, string $value, null|int|string $excludeId = null, ?string $idColumn = null, array $extra = []): int;

    /**
     * Count the number of objects in a collection with the given values.
     */
    public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int;
}
