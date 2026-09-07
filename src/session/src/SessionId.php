<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Support\Str;
use InvalidArgumentException;

final class SessionId
{
    public const int LENGTH = 40;

    /**
     * Generate a new session identifier.
     */
    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    /**
     * Determine if the given session identifier is valid.
     */
    public static function isValid(?string $id): bool
    {
        return is_string($id)
            && strlen($id) === self::LENGTH
            && ctype_alnum($id);
    }

    /**
     * Validate the given session identifier.
     */
    public static function validate(string $id): void
    {
        if (! self::isValid($id)) {
            throw new InvalidArgumentException('The session identifier is invalid.');
        }
    }
}
