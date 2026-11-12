<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data;

use Swoole\Coroutine\CanceledException;
use Throwable;

class ExactCancellationChecks
{
    public function checksException(Throwable $exception): bool
    {
        return $exception instanceof CanceledException;
    }

    public function checksPrevious(Throwable $exception): bool
    {
        return $exception->getPrevious() instanceof CanceledException;
    }

    public function checksRejectionReason(mixed $reason): bool
    {
        return $reason instanceof CanceledException;
    }

    public function checksNonException(int|string $value): bool
    {
        return $value instanceof CanceledException;
    }

    public function checksKnownCancellation(CanceledException $exception): bool
    {
        return $exception instanceof CanceledException;
    }
}
