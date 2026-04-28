<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Testing\ParallelConsoleOutput;
use Hypervel\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ParallelConsoleOutputTest extends TestCase
{
    public function testWrite()
    {
        $original = new BufferedOutput;
        $output = new ParallelConsoleOutput($original);

        $output->write('Running phpunit in 12 processes with hypervel/hypervel.');
        $this->assertEmpty($original->fetch());

        $output->write('Configuration read from phpunit.xml.dist');
        $this->assertEmpty($original->fetch());

        $output->write('... 3/3 (100%)');
        $this->assertSame('... 3/3 (100%)', $original->fetch());
    }
}
