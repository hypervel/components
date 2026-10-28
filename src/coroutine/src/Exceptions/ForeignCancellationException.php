<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Exceptions;

use RuntimeException;

/**
 * Another coroutine's cancellation, which this operation reports as an ordinary failure.
 */
class ForeignCancellationException extends RuntimeException
{
}
