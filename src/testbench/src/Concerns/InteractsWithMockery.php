<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Mockery;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

trait InteractsWithMockery
{
    /**
     * Tear down the testing environment using Mockery.
     */
    protected function tearDownTheTestEnvironmentUsingMockery(): void
    {
        if (! class_exists(Mockery::class) || ! $this instanceof PHPUnitTestCase) {
            return;
        }

        $container = Mockery::getContainer();

        if ($container !== null) { /* @phpstan-ignore notIdentical.alwaysTrue */
            /** @var int<0, max> $expectationCount */
            $expectationCount = $container->mockery_getExpectationCount();

            $this->addToAssertionCount($expectationCount);
        }

        Mockery::close();
    }
}
