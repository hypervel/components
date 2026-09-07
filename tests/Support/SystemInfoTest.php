<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use Symfony\Component\Process\Process;

#[RequiresOperatingSystem('Linux|Darwin')]
class SystemInfoTest extends TestCase
{
    public function testMemoryFormattingMethodsExposeMissingIntlExtension(): void
    {
        $script = <<<'PHP'
require $argv[1];

if (extension_loaded('intl')) {
    exit(77);
}

$systemInfo = new Hypervel\Support\SystemInfo;

foreach (['getTotalMemory', 'getMemoryLimitFormatted'] as $method) {
    try {
        $systemInfo->{$method}();
        fwrite(STDERR, "The [{$method}] dependency failure was hidden.\n");
        exit(1);
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'The "intl" PHP extension is required')) {
            throw $exception;
        }
    }
}
PHP;
        $process = new Process([
            PHP_BINARY,
            '-n',
            '-d',
            'memory_limit=128M',
            '-r',
            $script,
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ]);
        $process->run();

        if ($process->getExitCode() === 77) {
            $this->markTestSkipped('The intl extension is compiled into this PHP binary.');
        }

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }
}
