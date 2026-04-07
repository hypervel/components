<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Log a debug message to the logs.
 *
 * @return ($message is null ? \Psr\Log\LoggerInterface : null)
 */
function log(Arrayable|Jsonable|Stringable|array|string|null $message = null, array $context = []): ?LoggerInterface
{
    return logger($message, $context);
}
