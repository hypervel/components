<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Tests\TestCase;
use Symfony\Component\Process\Process;

class TimeLimitTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testNonYieldingTestsFailWithTheirIdentity(): void
    {
        $process = new Process(
            command: [
                PHP_BINARY,
                'vendor/bin/phpunit',
                '--no-progress',
                '--default-time-limit=1',
                'tests/Testing/PHPUnit/Fixtures/TimeLimitFixture.php',
            ],
            cwd: dirname(__DIR__, 3),
            timeout: 10,
        );
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'TimeLimitFixture::testNonYieldingWorkIsAborted',
            $output,
        );
        $this->assertStringContainsString('This test was aborted after 1 second', $output);
    }
}
