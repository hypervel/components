<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use InvalidArgumentException;

final class ReservedOptions
{
    /**
     * Prevent construction of this static utility.
     */
    private function __construct()
    {
    }

    /**
     * Reject options whose ownership belongs to dedicated APIs.
     */
    public static function reject(array $options, array $allowed, string $source): void
    {
        $messages = [
            'transport' => 'Use PendingRequest::transport(), Factory::setDefaultTransport(), or a registered connection.',
            'pool' => 'Use Factory::setDefaultPoolOptions() or a registered connection.',
            'handler' => 'Use PendingRequest::setHandler() to provide a request-specific handler.',
            'cookies' => 'Use PendingRequest::withCookies() to seed the request-owned cookie jar.',
            'transport_sharing' => 'Configure transport sharing through Factory::registerConnection().',
            'max_host_connections' => 'Configure connection caps through Factory::registerConnection().',
            'max_total_connections' => 'Configure connection caps through Factory::registerConnection().',
        ];

        foreach ($messages as $key => $remedy) {
            if (array_key_exists($key, $options) && ! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(
                    "The [{$key}] option is not allowed in {$source}. {$remedy}"
                );
            }
        }
    }
}
