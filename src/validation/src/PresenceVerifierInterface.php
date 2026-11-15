<?php

declare(strict_types=1);

namespace Hypervel\Validation;

interface PresenceVerifierInterface
{
    /**
     * Count the number of objects in a collection having the given value.
     */
    public function getCount(PresenceQuery $query, mixed $value): int;

    /**
     * Count the number of objects in a collection with the given values.
     */
    public function getMultiCount(PresenceQuery $query, array $values): int;
}
