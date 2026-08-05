<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Tests\TestCase;
use ReflectionClass;
use Symfony\Component\Process\Process;

class FacadeDocblocksTest extends TestCase
{
    /**
     * Subprocess tests don't need coroutines.
     */
    protected bool $runTestsInCoroutine = false;

    /**
     * Ensure every support facade has an up-to-date generated docblock.
     */
    public function testSupportFacadeDocblocksAreCurrent(): void
    {
        $root = dirname(__DIR__, 2);
        $facadeFiles = glob($root . '/src/support/src/Facades/*.php');

        $this->assertNotFalse($facadeFiles);

        $facades = array_values(array_filter(
            array_map(
                fn (string $path): string => 'Hypervel\Support\Facades\\' . pathinfo($path, PATHINFO_FILENAME),
                $facadeFiles,
            ),
            fn (string $facade): bool => ! (new ReflectionClass($facade))->isAbstract(),
        ));

        sort($facades);

        $this->assertNotEmpty($facades);

        $process = new Process(
            [PHP_BINARY, '-f', $root . '/src/facade-documenter/facade.php', '--', '--lint', ...$facades],
            timeout: 60,
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
    }
}
