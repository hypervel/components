<?php

declare(strict_types=1);

namespace Hypervel\JWT\Contracts;

interface ManagerContract
{
    public function encode(array $payload): string;

    public function decode(string $token, bool $validate = true, bool $checkBlacklist = true): array;

    public function refresh(string $token, bool $forceForever = false): string;

    public function invalidate(string $token, bool $forceForever = false): bool;
}
