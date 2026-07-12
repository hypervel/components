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
    public static function reject(array $options, bool $allowTransportSharing, string $source): void
    {
        $messages = [
            'pool' => 'HTTP clients are not object-pooled; named connections share their low-level transport handler automatically.',
            'handler' => 'Use PendingRequest::setHandler() to provide a request-specific handler.',
            'cookies' => 'Use PendingRequest::withCookies() to seed the request-owned cookie jar.',
        ];

        if (! $allowTransportSharing) {
            $messages['transport_sharing'] = 'Configure transport sharing through Factory::registerConnection().';
        }

        foreach ($messages as $key => $remedy) {
            if (array_key_exists($key, $options)) {
                throw new InvalidArgumentException(
                    "The [{$key}] option is not allowed in {$source}. {$remedy}"
                );
            }
        }
    }
}
