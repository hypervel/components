<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Exceptions;

/**
 * An independently canceled child whose owner remains active.
 */
class ChildCancellationException extends ForeignCancellationException
{
}
