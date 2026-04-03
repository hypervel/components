<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Testbench\PHPUnit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class HandlesAssertionsTest extends TestCase
{
    #[Test]
    public function itShouldMarkTheTestsAsSkippedWhenConditionIsTrue(): void
    {
        $this->expectOutputString('Successfully skipped current test');

        $this->markTestSkippedWhen(true, 'Successfully skipped current test');

        $this->assertTrue(false, 'Test incorrectly executed.');
    }

    #[Test]
    public function itShouldMarkTheTestsAsSkippedWhenConditionIsFalse(): void
    {
        $this->markTestSkippedWhen(function () {
            return false;
        }, 'Failed skipped current test');

        $this->assertTrue(true, 'Test is correctly executed.');
    }

    #[Test]
    public function itShouldMarkTheTestsAsSkippedUnlessConditionIsFalse(): void
    {
        $this->expectOutputString('Successfully skipped current test');

        $this->markTestSkippedUnless(false, 'Successfully skipped current test');

        $this->assertTrue(false, 'Test incorrectly executed.');
    }

    #[Test]
    public function itShouldMarkTheTestsAsSkippedUnlessConditionIsTrue(): void
    {
        $this->markTestSkippedUnless(function () {
            return true;
        }, 'Failed skipped current test');

        $this->assertTrue(true, 'Test is correctly executed.');
    }
}
