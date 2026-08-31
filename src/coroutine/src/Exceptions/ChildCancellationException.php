<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Exceptions;

use RuntimeException;

/**
 * An independently canceled child whose owner remains active.
 */
class ChildCancellationException extends RuntimeException
{
}
