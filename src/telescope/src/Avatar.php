<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Closure;
use Hyperf\Stringable\Str;

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
        if (empty($user['email'])) {
            return null;
        }

        if (isset(static::$callback)) {
            return static::resolve($user);
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
     * Find the custom avatar for a user.
     */
    protected static function resolve(array $user): ?string
    {
        if (static::$callback !== null) {
            return call_user_func(static::$callback, $user['id'], $user['email']);
        }

        return null;
    }
}
