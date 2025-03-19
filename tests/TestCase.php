<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }

        Mockery::close();

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
