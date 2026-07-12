<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify\Fixtures;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

class FixedClock implements ClockInterface
{
    public function __construct(private readonly int $timestamp)
    {
    }

    /**
     * Return the fixed time.
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}
