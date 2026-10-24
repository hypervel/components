<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\ConfigureTerminalDimensions;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use Symfony\Component\Process\Process;

class ConfigureTerminalDimensionsTest extends TestCase
{
    // Keep the PTY child outside Swoole's hooked subprocess wait.
    protected bool $runTestsInCoroutine = false;

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

    #[RunInSeparateProcess]
    #[DataProvider('partialDimensions')]
    public function testPreservesEachDimensionIndependently(string $preset, string $missing, string $expectedDefault): void
    {
        putenv("{$preset}=120");
        putenv($missing);

        (new ConfigureTerminalDimensions)->bootstrap(new Application);

        $this->assertSame('120', getenv($preset));
        $this->assertSame($expectedDefault, getenv($missing));
    }

    /**
     * Provide each individually configured terminal dimension.
     */
    public static function partialDimensions(): array
    {
        return [
            'width' => ['COLUMNS', 'LINES', '24'],
            'height' => ['LINES', 'COLUMNS', '80'],
        ];
    }

    public function testLeavesRealTerminalDimensionsUnchanged(): void
    {
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/../Fixtures/configure-terminal-dimensions.php'],
            env: ['COLUMNS' => false, 'LINES' => false],
            timeout: 10,
        );
        $process->setPty(true);
        $process->mustRun();

        $this->assertSame([true, false, false], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
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
