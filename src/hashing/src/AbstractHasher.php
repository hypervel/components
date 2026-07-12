<?php

declare(strict_types=1);

namespace Hypervel\Hashing;

abstract class AbstractHasher
{
    /**
     * Get information about the given hashed value.
     */
    public function info(string $hashedValue): array
    {
        return password_get_info($hashedValue);
    }

    /**
     * Check the given plain value against a hash.
     */
    public function check(string $value, ?string $hashedValue, array $options = []): bool
    {
        if (! $this->hasHash($hashedValue)) {
            return false;
        }

        return password_verify($value, $hashedValue);
    }

    /**
     * Determine whether a hash value is present.
     */
    protected function hasHash(?string $hashedValue): bool
    {
        return ! is_null($hashedValue) && strlen($hashedValue) > 0;
    }
}
