<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Symfony\Component\Process\Process;

class BootstrapTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected string $directory;

    /**
     * Prepare a process-isolated proxy directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = ParallelTesting::tempDir('GuzzleBootstrapTest');
        (new Filesystem)->deleteDirectory($this->directory);
    }

    /**
     * Remove generated subprocess proxies.
     */
    protected function tearDown(): void
    {
        try {
            (new Filesystem)->deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    public function testColdAndCachedBootActivateOwnershipWithoutObservability(): void
    {
        for ($boot = 0; $boot < 2; ++$boot) {
            $process = $this->runFixture('normal');
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $parserState = $boot === 0 ? 'loaded' : 'unloaded';
            $this->assertSame("parser:{$parserState}\nprinter:{$parserState}\ncold-boot-enforced\nowner\n", $process->getOutput());
        }
    }

    public function testLoadingAnOriginalTargetBeforeGenerationFailsWithGuidance(): void
    {
        $process = $this->runFixture('early');

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('GuzzleHttp\Promise\Promise', $process->getErrorOutput());
        $this->assertStringContainsString('Bind a factory in register()', $process->getErrorOutput());
    }

    public function testProductionShutdownStillDrainsOutsideCallbacks(): void
    {
        $process = $this->runFixture('shutdown');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("outside-shutdown\n", $process->getOutput());
    }

    public function testOutsideTransfersSurviveAnotherCoroutinesGarbageCollection(): void
    {
        $process = $this->runFixture('outside-transfer');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("outside-transfer-retained-and-released\n", $process->getOutput());
    }

    /**
     * Run production registration in a clean process without the PHPUnit extension.
     */
    protected function runFixture(string $mode): Process
    {
        $process = new Process([
            PHP_BINARY,
            '-d', 'display_errors=stderr',
            '-d', 'log_errors=0',
            __DIR__ . '/Fixtures/bootstrap.php',
            dirname(__DIR__, 4) . '/vendor/autoload.php',
            $mode,
            $this->directory,
        ], timeout: 15);
        $process->run();

        return $process;
    }
}
