<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

use GuzzleHttp\Utils;
use InvalidArgumentException;

class HandlerFactory
{
    /**
     * Create a Guzzle transport handler for clients shared by concurrent coroutines.
     */
    public static function create(array $options = []): callable
    {
        foreach (['max_host_connections', 'max_total_connections'] as $option) {
            if (isset($options[$option])) {
                throw new InvalidArgumentException(
                    "The [{$option}] option is not supported. Guzzle applies connection caps through a shared multi-handler, which cannot be driven safely by concurrent coroutines. Use bounded coroutine fan-out or rate limiting instead."
                );
            }
        }

        return Utils::chooseHandler($options);
    }
}
