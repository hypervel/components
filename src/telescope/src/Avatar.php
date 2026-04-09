<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Closure;
use Hypervel\Support\Str;

class Avatar
{
    /**
     * The callback that should be used to get the Telescope user avatar.
     */
    protected static ?Closure $callback;

    /**
     * Get an avatar URL for an entry user.
     */
    public static function url(array $user): ?string
    {
        if (isset(static::$callback)) {
            return static::resolve($user);
        }

        if (empty($user['email'])) {
            return null;
        }

        return 'https://www.gravatar.com/avatar/' . md5(Str::lower($user['email'])) . '?s=200';
    }

    /**
     * Register the Telescope user avatar callback.
     */
    public static function register(Closure $callback): void
    {
        static::$callback = $callback;
    }

    /**
     * Flush the avatar callback.
     */
    public static function flushState(): void
    {
        static::$callback = null;
    }

    /**
     * Find the custom avatar for a user.
     */
    protected static function resolve(array $user): ?string
    {
        if (static::$callback !== null) {
            return call_user_func(static::$callback, $user['id'] ?? null, $user['email'] ?? null);
        }

        return null;
    }
}
