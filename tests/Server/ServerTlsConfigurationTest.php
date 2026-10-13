<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Tests\TestCase;
use Symfony\Component\Process\Process;

class ServerTlsConfigurationTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testMalformedDefaultCertificatesAreRejected(): void
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/Fixtures/invalid_tls_configuration.php',
        ]);
        $process->setTimeout(2);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'ssl_init() failed',
            $process->getOutput() . $process->getErrorOutput(),
        );
    }
}
