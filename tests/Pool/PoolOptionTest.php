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
}
