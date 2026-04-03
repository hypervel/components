<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class WithConfigTest extends TestCase
{
    #[Test]
    #[WithConfig('testbench.attribute', true)]
    public function itCanResolveDefinedConfiguration(): void
    {
        $this->assertTrue(config('testbench.attribute'));
    }

    #[Test]
    public function itDoesNotPersistDefinedConfigurationBetweenTests(): void
    {
        $this->assertNull(config('testbench.attribute'));
    }
}
