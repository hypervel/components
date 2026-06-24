<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Pool\PoolOption;
use Hypervel\Tests\TestCase;

class PoolOptionTest extends TestCase
{
    public function testMaxLifetimeDefaultsToDisabled(): void
    {
        $option = new PoolOption;

        $this->assertSame(-1.0, $option->getMaxLifetime());
    }

    public function testMaxLifetimeCanBeConfigured(): void
    {
        $option = new PoolOption(maxLifetime: 120.0);

        $this->assertSame(120.0, $option->getMaxLifetime());
    }

    public function testMaxLifetimeCanBeChanged(): void
    {
        $option = new PoolOption;

        $this->assertSame($option, $option->setMaxLifetime(30.0));
        $this->assertSame(30.0, $option->getMaxLifetime());
    }

    public function testJitteredLifetimeDeadlineDefaultsToDisabled(): void
    {
        $this->assertSame(0.0, PoolOption::jitteredLifetimeDeadline(100.0, -1.0));
        $this->assertSame(0.0, PoolOption::jitteredLifetimeDeadline(100.0, 0.0));
    }

    public function testJitteredLifetimeDeadlineKeepsConfiguredLifetimeAsUpperBound(): void
    {
        $createdAt = 100.0;
        $maxLifetime = 60.0;

        $deadline = PoolOption::jitteredLifetimeDeadline($createdAt, $maxLifetime);

        $this->assertGreaterThanOrEqual(
            $createdAt + ($maxLifetime * PoolOption::MIN_LIFETIME_JITTER_BASIS / PoolOption::LIFETIME_JITTER_SCALE),
            $deadline
        );
        $this->assertLessThanOrEqual($createdAt + $maxLifetime, $deadline);
    }
}
