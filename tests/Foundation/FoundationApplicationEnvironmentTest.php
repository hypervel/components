<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Tests\TestCase;
use Symfony\Component\Process\Process;

class FoundationApplicationEnvironmentTest extends TestCase
{
    public function testApplicationRequiresSwoole(): void
    {
        $process = new Process([PHP_BINARY, '-n', __DIR__ . '/Fixtures/configure-application.php']);
        $process->run();

        $this->assertSame(1, $process->getExitCode(), $process->getOutput());
        $this->assertSame('Hypervel requires the Swoole extension. OpenSwoole is not supported.', $process->getErrorOutput());
    }

    public function testApplicationRequiresSwooleShortNamesToBeDisabled(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'swoole.use_shortname=On', __DIR__ . '/Fixtures/configure-application.php']);
        $process->run();

        $this->assertSame(1, $process->getExitCode(), $process->getOutput());
        $this->assertSame(
            'Swoole short function names must be disabled. Set swoole.use_shortname=Off in your php.ini.',
            $process->getErrorOutput()
        );
    }
}
