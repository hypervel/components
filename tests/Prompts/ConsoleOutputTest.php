<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Output\ConsoleOutput;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Output\StreamOutput;

class ConsoleOutputTest extends TestCase
{
    public function testCorrectlyCountsTrailingNewlinesWithUnixLineEndings()
    {
        $output = $this->createSilentOutput();
        $ref = new ReflectionProperty($output, 'newLinesWritten');

        $ref->setValue($output, 0);
        $output->writeln('Hello');
        $this->assertSame(1, $ref->getValue($output));

        $ref->setValue($output, 0);
        $output->writeln('Hello');
        $output->writeln('');
        $this->assertSame(2, $ref->getValue($output));
    }

    public function testCorrectlyCountsTrailingNewlinesWithWindowsLineEndings()
    {
        $output = $this->createSilentOutput();
        $ref = new ReflectionProperty($output, 'newLinesWritten');

        // Simulate what happens when PHP_EOL is \r\n:
        // writeln() appends PHP_EOL to the message, then doWrite counts trailing newlines.
        // On Windows, PHP_EOL is \r\n. The old rtrim-based code would count \r and \n
        // as separate characters, doubling the count. The regex fix handles this correctly.
        $ref->setValue($output, 0);
        $output->writeln('Hello');
        $count = $ref->getValue($output);

        // Regardless of platform, a single writeln should count as 1 trailing newline
        $this->assertSame(1, $count);
    }

    public function testAccumulatesNewlinesForBlankLines()
    {
        $output = $this->createSilentOutput();
        $ref = new ReflectionProperty($output, 'newLinesWritten');

        $ref->setValue($output, 0);
        $output->writeln('Hello');
        $output->writeln('');
        $output->writeln('');

        $this->assertSame(3, $ref->getValue($output));
    }

    public function testResetsNewlineCountForNonBlankLines()
    {
        $output = $this->createSilentOutput();
        $ref = new ReflectionProperty($output, 'newLinesWritten');

        $ref->setValue($output, 0);
        $output->writeln('Hello');
        $output->writeln('');
        $output->writeln('World');

        // 'World' is non-blank, so count resets to 1 (its own trailing newline)
        $this->assertSame(1, $ref->getValue($output));
    }

    public function testCountsZeroTrailingNewlinesForMessagesWithoutNewlineFlag()
    {
        $output = $this->createSilentOutput();
        $ref = new ReflectionProperty($output, 'newLinesWritten');

        $ref->setValue($output, 0);
        $output->write('Hello');

        $this->assertSame(0, $ref->getValue($output));
    }

    /**
     * Create a ConsoleOutput that writes to a temporary stream instead of stdout.
     */
    private function createSilentOutput(): ConsoleOutput
    {
        $output = new ConsoleOutput;
        $stream = fopen('php://memory', 'rw');

        // Replace the underlying stream so output doesn't leak to stdout
        $ref = new ReflectionProperty(StreamOutput::class, 'stream');
        $ref->setValue($output, $stream);

        return $output;
    }
}
