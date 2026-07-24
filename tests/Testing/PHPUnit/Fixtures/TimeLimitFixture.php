<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures;

use Hypervel\Tests\TestCase;

class TimeLimitFixture extends TestCase
{
    public function testNonYieldingWorkIsAborted(): void
    {
        while (true);
    }
}
