<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Http;

use Hypervel\Support\Str;
use Sentry\DataCollection\KeyValueDataFilter;

/**
 * Filters the values of the cookies Hypervel uses for sessions and authentication because they are very sensitive.
 *
 * @internal
 */
final class SensitiveCookieFilter
{
    /**
     * Prevent instantiation of the filter.
     */
    private function __construct()
    {
    }

    /**
     * Filter the value of the given cookie.
     *
     * Cookie parameter arrays store numeric cookie names as integer keys.
     */
    public static function filterValue(int|string $name, mixed $value): mixed
    {
        $name = (string) $name;

        if ($name === 'XSRF-TOKEN'
            || str_starts_with($name, 'remember_')
            || Str::is(config()->string('session.cookie'), $name)) {
            return KeyValueDataFilter::FILTERED_VALUE;
        }

        return $value;
    }
}
