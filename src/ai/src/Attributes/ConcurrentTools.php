<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class ConcurrentTools
{
    /**
     * Limit the number of tools the agent may execute concurrently.
     */
    public function __construct(public readonly int $max)
    {
        if ($max < 1) {
            throw new InvalidArgumentException('Tool concurrency must be at least one.');
        }
    }
}
