<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Profile;

use Hypervel\Testing\Profile\ProfileTracker;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ProfileTrackerTest extends TestCase
{
    #[Test]
    public function itKeepsTheTenSlowestTestsInDescendingOrder(): void
    {
        $tracker = new ProfileTracker;

        foreach (range(1, 12) as $index) {
            $tracker->start("test-{$index}", 0.0);
            $tracker->stop("test-{$index}", "Test {$index}", (float) $index);
        }

        $slowTests = $tracker->slowTests();

        $this->assertCount(10, $slowTests);
        $this->assertSame('Test 12', $slowTests[0]['name']);
        $this->assertSame(12.0, $slowTests[0]['duration']);
        $this->assertSame('Test 3', $slowTests[9]['name']);
        $this->assertSame(3.0, $slowTests[9]['duration']);
    }
}
