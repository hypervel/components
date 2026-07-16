<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Context\CoroutineContext;
use Hypervel\Support\Once;
use Hypervel\Testing\PHPUnit\AfterEachTestSubscriber;
use Hypervel\Tests\TestCase;

class AfterEachTestSubscriberNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testFrameworkCleanupLeavesTheNonCoroutineContextEmpty(): void
    {
        Once::disable();
        Once::instance();

        $this->assertNotEmpty(CoroutineContext::getContainer());

        (new class extends AfterEachTestSubscriber {
            public function flushFrameworkStateForTest(): void
            {
                $this->flushFrameworkState();
            }
        })->flushFrameworkStateForTest();

        $this->assertSame([], CoroutineContext::getContainer());
    }
}
