<?php

declare(strict_types=1);

namespace Hypervel\JWT\Contracts;

interface BlacklistContract
{
    /**
     * Add the token (jti claim) to the blacklist.
     */
    public function add(array $payload): bool;

    /**
     * Add the token (jti claim) to the blacklist indefinitely.
     */
    public function addForever(array $payload): bool;

    /**
     * Determine whether the token has been blacklisted.
     */
    public function has(array $payload): bool;

    /**
     * Remove the token (jti claim) from the blacklist.
     */
    public function remove(array $payload): bool;

    /**
     * Remove all tokens from the blacklist.
     */
    public function clear(): bool;
}
