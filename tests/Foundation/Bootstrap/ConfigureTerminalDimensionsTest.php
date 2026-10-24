<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\ConfigureTerminalDimensions;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use Symfony\Component\Console\Terminal;
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

        $this->resetTerminalDimensions();
        (new ConfigureTerminalDimensions)->bootstrap(new Application);

        $this->assertSame('200', getenv('COLUMNS'));
        $this->assertSame('50', getenv('LINES'));
        $this->assertSame(200, (new Terminal)->getWidth());
        $this->assertSame(50, (new Terminal)->getHeight());
    }

    #[RunInSeparateProcess]
    public function testConfiguresTerminalDimensionsWhenTheyAreAbsent(): void
    {
        putenv('COLUMNS');
        putenv('LINES');

        $this->assertFalse(getenv('COLUMNS'));
        $this->assertFalse(getenv('LINES'));
        $this->assertFalse(stream_isatty(STDOUT));

        $this->resetTerminalDimensions();
        (new ConfigureTerminalDimensions)->bootstrap(new Application);

        $this->assertFalse(getenv('COLUMNS'));
        $this->assertFalse(getenv('LINES'));
        // Symfony also returns 80 after a failed probe; ensure neither lookup needs one.
        $this->assertSame(80, (new ReflectionProperty(Terminal::class, 'width'))->getValue());
        $this->assertSame(24, (new ReflectionProperty(Terminal::class, 'height'))->getValue());
        $this->assertSame(80, (new Terminal)->getWidth());
        $this->assertSame(24, (new Terminal)->getHeight());
    }

    #[RunInSeparateProcess]
    #[DataProvider('partialDimensions')]
    public function testPreservesEachDimensionIndependently(string $preset, string $missing, int $expectedWidth, int $expectedHeight): void
    {
        putenv("{$preset}=120");
        putenv($missing);

        $this->resetTerminalDimensions();
        (new ConfigureTerminalDimensions)->bootstrap(new Application);

        $this->assertSame('120', getenv($preset));
        $this->assertFalse(getenv($missing));
        $this->assertSame($expectedWidth, (new Terminal)->getWidth());
        $this->assertSame($expectedHeight, (new Terminal)->getHeight());
    }

    /**
     * Provide each individually configured terminal dimension.
     */
    public static function partialDimensions(): array
    {
        return [
            'width' => ['COLUMNS', 'LINES', 120, 24],
            'height' => ['LINES', 'COLUMNS', 80, 120],
        ];
    }

    #[RunInSeparateProcess]
    public function testPreservesRestoredEnvironmentDimensions(): void
    {
        putenv('COLUMNS=120');
        putenv('LINES=40');
        $this->resetTerminalDimensions();

        $this->withEnvironmentValues(['COLUMNS' => null, 'LINES' => null], function (): void {
            ConfigureTerminalDimensions::configure();

            $this->assertFalse(getenv('COLUMNS'));
            $this->assertFalse(getenv('LINES'));
            $this->assertSame(80, (new Terminal)->getWidth());
            $this->assertSame(24, (new Terminal)->getHeight());
        });

        ConfigureTerminalDimensions::configure();

        $this->assertSame('120', getenv('COLUMNS'));
        $this->assertSame('40', getenv('LINES'));
        $this->assertSame(120, (new Terminal)->getWidth());
        $this->assertSame(40, (new Terminal)->getHeight());
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

        $this->assertSame([true, false, false, null, null], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
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

    public function testTerminalDimensionsAreConfiguredBeforeProvidersAreRegistered(): void
    {
        foreach ([HttpKernel::class, ConsoleKernel::class] as $kernel) {
            $bootstrappers = $this->getBootstrappers($kernel);
            $providersIndex = array_search(RegisterProviders::class, $bootstrappers, true);
            $terminalIndex = array_search(ConfigureTerminalDimensions::class, $bootstrappers, true);

            $this->assertIsInt($providersIndex);
            $this->assertIsInt($terminalIndex);
            $this->assertLessThan($providersIndex, $terminalIndex);
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

    /**
     * Clear dimensions supplied by the test runner before exercising bootstrap.
     */
    private function resetTerminalDimensions(): void
    {
        (new ReflectionProperty(Terminal::class, 'width'))->setValue(null, null);
        (new ReflectionProperty(Terminal::class, 'height'))->setValue(null, null);
    }
}
