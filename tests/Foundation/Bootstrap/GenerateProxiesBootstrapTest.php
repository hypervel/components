<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Foundation\Bootstrap\BootProviders;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Tests\TestCase;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class GenerateProxiesBootstrapTest extends TestCase
{
    public function testHttpKernelIncludesGenerateProxies()
    {
        $bootstrappers = $this->getBootstrappers(HttpKernel::class);

        $this->assertContains(GenerateProxies::class, $bootstrappers);
    }

    public function testConsoleKernelIncludesGenerateProxies()
    {
        $bootstrappers = $this->getBootstrappers(ConsoleKernel::class);

        $this->assertContains(GenerateProxies::class, $bootstrappers);
    }

    public function testGenerateProxiesRunsAfterRegisterProviders()
    {
        $bootstrappers = $this->getBootstrappers(HttpKernel::class);

        $registerIndex = array_search(RegisterProviders::class, $bootstrappers, true);
        $generateIndex = array_search(GenerateProxies::class, $bootstrappers, true);

        $this->assertIsInt($registerIndex);
        $this->assertIsInt($generateIndex);
        $this->assertGreaterThan($registerIndex, $generateIndex);
    }

    public function testGenerateProxiesRunsBeforeBootProviders()
    {
        $bootstrappers = $this->getBootstrappers(HttpKernel::class);

        $generateIndex = array_search(GenerateProxies::class, $bootstrappers, true);
        $bootIndex = array_search(BootProviders::class, $bootstrappers, true);

        $this->assertIsInt($generateIndex);
        $this->assertIsInt($bootIndex);
        $this->assertLessThan($bootIndex, $generateIndex);
    }

    /**
     * Read the bootstrappers property from a kernel class via reflection.
     */
    private function getBootstrappers(string $kernelClass): array
    {
        $property = new ReflectionProperty($kernelClass, 'bootstrappers');

        return $property->getDefaultValue();
    }
}
