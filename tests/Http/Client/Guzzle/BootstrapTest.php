<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use Hypervel\Tests\TestCase;
use Symfony\Component\Process\Process;

class BootstrapTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

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
        ], timeout: 15);
        $process->run();

        return $process;
    }
}
