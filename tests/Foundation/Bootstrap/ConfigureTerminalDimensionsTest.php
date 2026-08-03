<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\ConfigureTerminalDimensions;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;

class ConfigureTerminalDimensionsTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testPreservesExistingTerminalDimensions(): void
    {
        putenv('COLUMNS=200');
        putenv('LINES=50');

        $this->assertFalse(stream_isatty(STDOUT));

        (new ConfigureTerminalDimensions)->bootstrap(new Application);

        $this->assertSame('200', getenv('COLUMNS'));
        $this->assertSame('50', getenv('LINES'));
    }

    #[RunInSeparateProcess]
    public function testConfiguresTerminalDimensionsWhenTheyAreAbsent(): void
    {
        putenv('COLUMNS');
        putenv('LINES');

        $this->assertFalse(getenv('COLUMNS'));
        $this->assertFalse(getenv('LINES'));
        $this->assertFalse(stream_isatty(STDOUT));

        (new ConfigureTerminalDimensions)->bootstrap(new Application);

        $this->assertSame('80', getenv('COLUMNS'));
        $this->assertSame('24', getenv('LINES'));
    }

    public function testKernelsIncludeTerminalDimensionsBootstrapper(): void
    {
        foreach ([HttpKernel::class, ConsoleKernel::class] as $kernel) {
            $this->assertContains(
                ConfigureTerminalDimensions::class,
                $this->getBootstrappers($kernel),
            );
        }
    }

    public function testTerminalDimensionsAreConfiguredAfterEnvironmentVariablesAreLoaded(): void
    {
        foreach ([HttpKernel::class, ConsoleKernel::class] as $kernel) {
            $bootstrappers = $this->getBootstrappers($kernel);
            $environmentIndex = array_search(LoadEnvironmentVariables::class, $bootstrappers, true);
            $terminalIndex = array_search(ConfigureTerminalDimensions::class, $bootstrappers, true);

            $this->assertIsInt($environmentIndex);
            $this->assertIsInt($terminalIndex);
            $this->assertGreaterThan($environmentIndex, $terminalIndex);
        }
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
