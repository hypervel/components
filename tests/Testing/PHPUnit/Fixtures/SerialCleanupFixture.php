<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures;

use Hypervel\Testing\ParallelRunner;
use Hypervel\Tests\TestCase;

class SerialCleanupFixture extends TestCase
{
    public function testSerialTestsDoNotLoadTheParallelRunner(): void
    {
        $this->assertFalse(class_exists(ParallelRunner::class, false));
    }

    public function testCleanupDoesNotLoadTheParallelRunner(): void
    {
        $this->assertFalse(class_exists(ParallelRunner::class, false));
    }
}
