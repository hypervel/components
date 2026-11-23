<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Support;

use InvalidArgumentException;

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
     * The cached AAGUID to icon mapping, keyed by theme.
     *
     * @var null|array<string, array<string, string>>
     */
    protected static ?array $icons = null;

    /**
     * Get the authenticator label for the given AAGUID.
     */
    public static function labelFor(string $aaguid): ?string
    {
        return static::all()[$aaguid] ?? null;
    }

    /**
     * Get the authenticator icon (data URI) for the given AAGUID.
     *
     * @param 'dark'|'light' $theme
     *
     * @throws InvalidArgumentException
     */
    public static function iconFor(string $aaguid, string $theme = 'light'): ?string
    {
        if (! in_array($theme, ['light', 'dark'], true)) {
            throw new InvalidArgumentException("Unsupported icon theme [{$theme}]. Expected 'light' or 'dark'.");
        }

        return static::icons()[$aaguid][$theme] ?? null;
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
     * Get all AAGUID to icon mappings.
     *
     * Icons live in a separate resource file so that resolving a name never loads them.
     *
     * @return array<string, array<string, string>>
     */
    protected static function icons(): array
    {
        /** @var array<string, array<string, string>> */
        return static::$icons ??= require __DIR__ . '/../../resources/aaguid-icons.php';
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$aaguids = null;
        static::$icons = null;
    }
}
