<?php

declare(strict_types=1);

namespace Hypervel\JWT\Contracts;

interface ManagerContract
{
    /**
     * Encode a payload into a token.
     */
    public function encode(array $payload): string;

    /**
     * Decode a token into its payload.
     */
    public function decode(string $token, bool $validate = true, bool $checkBlacklist = true): array;

    /**
     * Refresh a token.
     */
    public function refresh(
        string $token,
        bool $forceForever = false,
        bool $resetClaims = false,
        array $customClaims = [],
        int|false|null $ttl = false,
    ): string;

    /**
     * Invalidate a token.
     */
    public function invalidate(string $token, bool $forceForever = false): bool;

    /**
     * Determine if the blacklist is enabled.
     */
    public function hasBlacklistEnabled(): bool;
}
