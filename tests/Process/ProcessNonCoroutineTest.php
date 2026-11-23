<?php

declare(strict_types=1);

namespace Hypervel\Tests\Process;

use Hypervel\Process\Factory;
use Hypervel\Tests\TestCase;

class ProcessNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testAsynchronousProcessCanRunOutsideACoroutine(): void
    {
        $process = (new Factory)->start([PHP_BINARY, '-r', 'echo "done";']);

        try {
            $this->assertSame('done', $process->wait()->output());
        } finally {
            $process->stop(0);
        }
    }
}
