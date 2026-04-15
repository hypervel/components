<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ApplicationProvidersTest extends TestCase
{
    #[Test]
    public function itLoadedTheDefaultServices(): void
    {
        $this->assertTrue($this->app->bound('blade.compiler'));
        $this->assertFalse($this->app->resolved('blade.compiler'));
    }
}
