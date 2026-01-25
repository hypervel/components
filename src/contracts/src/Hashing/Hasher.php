<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Hashing;

interface Hasher
{
    /**
     * Get information about the given hashed value.
     */
    public function info(string $hashedValue): array;

    /**
     * Hash the given value.
     */
    public function make(string $value, array $options = []): string;

    /**
     * Check the given plain value against a hash.
     */
    public function check(string $value, ?string $hashedValue, array $options = []): bool;

    /**
     * Check if the given hash has been hashed using the given options.
     */
    public function needsRehash(string $hashedValue, array $options = []): bool;
}
