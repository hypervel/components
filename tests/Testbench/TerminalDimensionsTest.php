<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\DotenvManager;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
class TerminalDimensionsTest extends TestCase
{
    protected ?string $environmentPath = null;

    public function testApplicationCreationConfiguresHeadlessTerminalDimensions(): void
    {
        putenv('COLUMNS');
        putenv('LINES');
        unset($_ENV['COLUMNS'], $_ENV['LINES'], $_SERVER['COLUMNS'], $_SERVER['LINES']);

        $this->assertFalse(stream_isatty(STDOUT));

        $this->refreshApplication();

        $this->assertSame('80', getenv('COLUMNS'));
        $this->assertSame('24', getenv('LINES'));
    }

    public function testApplicationCreationPreservesDimensionsFromItsEnvironmentFile(): void
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

            $this->refreshApplication();

            $this->assertSame('120', getenv('COLUMNS'));
            $this->assertSame('40', getenv('LINES'));
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
