<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Saloon\Exceptions\PendingRequestException;

final class RequestOptionValidator
{
    /**
     * Options whose values are owned by the Saloon request lifecycle.
     *
     * @var list<string>
     */
    private const array RESERVED_OPTIONS = [
        'headers',
        'query',
        'cookies',
        'body',
        'json',
        'form_params',
        'multipart',
        'auth',
        'delay',
        'http_errors',
    ];

    private function __construct()
    {
    }

    /**
     * Validate transport options at a Saloon-owned boundary.
     *
     * @param array<string, mixed> $options
     */
    public static function validate(array $options, string $source): void
    {
        foreach (self::RESERVED_OPTIONS as $option) {
            if (array_key_exists($option, $options)) {
                throw new PendingRequestException(
                    "The [{$option}] option cannot be set in {$source}; use the Saloon request API instead.",
                );
            }
        }
    }
}
