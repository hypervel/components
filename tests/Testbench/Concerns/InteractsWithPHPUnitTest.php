<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class InteractsWithPHPUnitTest extends TestCase
{
    #[Test]
    public function itCanResolveTheCorrectClassAndMethodName(): void
    {
        $this->assertSame(__CLASS__, $this->resolvePhpUnitTestClassName());
        $this->assertSame('itCanResolveTheCorrectClassAndMethodName', $this->resolvePhpUnitTestMethodName());
    }
}
