<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Exception;

use RuntimeException;

/**
 * Exception thrown when attempting to use a closed channel.
 */
class ChannelClosedException extends RuntimeException
{
}
