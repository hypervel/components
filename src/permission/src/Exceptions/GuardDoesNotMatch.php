<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use Hypervel\Support\Collection;
use InvalidArgumentException;

class GuardDoesNotMatch extends InvalidArgumentException
{
    /**
     * Create a new guard mismatch exception.
     */
    public static function create(string $givenGuard, Collection $expectedGuards): static
    {
        return new static(__('The given role or permission should use guard `:expected` instead of `:given`.', [
            'expected' => $expectedGuards->implode(', '),
            'given' => $givenGuard,
        ]));
    }
}
