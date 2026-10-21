<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Contracts;

use SensitiveParameter;

interface ProviderContract
{
    /**
     * Create a JSON Web Token.
     */
    public function encode(array $payload): string;

    /**
     * Decode a JSON Web Token.
     */
    public function decode(#[SensitiveParameter] string $token): array;
}
