<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Mockery;
use PHPUnit\Framework\TestCase;

abstract class UnitTestCase extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }
}
