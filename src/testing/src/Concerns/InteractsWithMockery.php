<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

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

        /** @var int<0, max> $expectationCount */
        $expectationCount = Mockery::getContainer()->mockery_getExpectationCount();

        $this->addToAssertionCount($expectationCount);

        Mockery::close();
    }
}
