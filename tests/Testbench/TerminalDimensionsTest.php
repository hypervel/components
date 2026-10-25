<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Bootstrap\ConfigureTerminalDimensions;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionProperty;
use Symfony\Component\Console\Terminal;

#[RunTestsInSeparateProcesses]
class TerminalDimensionsTest extends TestCase
{
    protected ?string $environmentPath = null;

    public function testApplicationCreationConfiguresHeadlessTerminalDimensions(): void
    {
        putenv('COLUMNS');
        putenv('LINES');
        unset($_ENV['COLUMNS'], $_ENV['LINES'], $_SERVER['COLUMNS'], $_SERVER['LINES']);
        (new ReflectionProperty(Terminal::class, 'width'))->setValue(null, null);
        (new ReflectionProperty(Terminal::class, 'height'))->setValue(null, null);

        $this->assertFalse(stream_isatty(STDOUT));

        $this->refreshApplication();

        $this->assertFalse(getenv('COLUMNS'));
        $this->assertFalse(getenv('LINES'));
        $this->assertSame(80, (new Terminal)->getWidth());
        $this->assertSame(24, (new Terminal)->getHeight());
    }

    public function testEnvironmentFileOverridesEarlierTerminalDefaults(): void
    {
        $this->environmentPath = ParallelTesting::tempDir('TestbenchTerminalDimensions');
        $files = new Filesystem;
        $files->deleteDirectory($this->environmentPath);
        $files->ensureDirectoryExists($this->environmentPath);

        try {
            $files->put($this->environmentPath . '/.env', "COLUMNS=120\nLINES=40\n");
            DotenvManager::flushState();
            putenv('COLUMNS');
            putenv('LINES');
            unset($_ENV['COLUMNS'], $_ENV['LINES'], $_SERVER['COLUMNS'], $_SERVER['LINES']);

            // A unit test can initialize terminal fallbacks before it boots an application.
            ConfigureTerminalDimensions::configure();

            $this->refreshApplication();

            $this->assertSame('120', getenv('COLUMNS'));
            $this->assertSame('40', getenv('LINES'));
            $this->assertSame('120', Env::get('COLUMNS'));
            $this->assertSame('40', Env::get('LINES'));
            $this->assertSame(120, (new Terminal)->getWidth());
            $this->assertSame(40, (new Terminal)->getHeight());
        } finally {
            $files->deleteDirectory($this->environmentPath);
        }
    }

    /**
     * Resolve the application with the test's isolated environment directory.
     */
    protected function resolveApplication(): ApplicationContract
    {
        $app = parent::resolveApplication();

        if ($this->environmentPath !== null) {
            $app->useEnvironmentPath($this->environmentPath);
        }

        return $app;
    }
}
