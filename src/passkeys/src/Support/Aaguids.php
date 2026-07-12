<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Support;

/**
 * AAGUID to authenticator name mapping.
 *
 * @see https://github.com/passkeydeveloper/passkey-authenticator-aaguids
 */
class Aaguids
{
    /**
     * The cached AAGUID to name mapping.
     *
     * @var null|array<string, string>
     */
    protected static ?array $aaguids = null;

    /**
     * Get the authenticator label for the given AAGUID.
     */
    public static function labelFor(string $aaguid): ?string
    {
        return static::all()[$aaguid] ?? null;
    }

    /**
     * Get the unknown AAGUID value.
     */
    public static function unknown(): string
    {
        return '00000000-0000-0000-0000-000000000000';
    }

    /**
     * Get all AAGUID to name mappings.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        /** @var array<string, string> */
        return static::$aaguids ??= require __DIR__ . '/../../resources/aaguids.php';
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$aaguids = null;
    }
}
