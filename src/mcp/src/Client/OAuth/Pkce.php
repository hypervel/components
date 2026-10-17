<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

class Pkce
{
    /**
     * Create a PKCE verifier and challenge pair.
     */
    public function __construct(
        public string $verifier,
        public string $challenge,
    ) {
    }

    /**
     * Generate a cryptographic verifier and its S256 challenge.
     */
    public static function generate(): self
    {
        $verifier = self::base64Url(random_bytes(64));
        $challenge = self::base64Url(hash('sha256', $verifier, true));

        return new self($verifier, $challenge);
    }

    /**
     * Encode bytes as an unpadded URL-safe string.
     */
    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
