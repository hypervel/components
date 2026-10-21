<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Symfony\Component\Process\Process;

abstract class TestCase extends \Hypervel\Testbench\TestCase
{
    use InteractsWithPublishedFiles;

    /**
     * Assert that the given generated PHP file compiles.
     */
    protected function assertPhpFileCompiles(string $file): void
    {
        $lint = new Process([PHP_BINARY, '-l', $this->app->basePath($file)]);
        $lint->run();

        $this->assertTrue($lint->isSuccessful(), $lint->getOutput() . $lint->getErrorOutput());
    }
}
