<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\OutputStyle;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class OutputStyleTest extends TestCase
{
    public function testDetectsNewLine()
    {
        $bufferedOutput = new BufferedOutput;

        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $this->assertSame(1, $style->newLinesWritten());

        $style->newLine();
        $this->assertSame(2, $style->newLinesWritten());
    }

    public function testDetectsNewLineOnUnderlyingOutput()
    {
        $bufferedOutput = new BufferedOutput;

        $underlyingStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);
        $style = new OutputStyle(new ArrayInput([]), $underlyingStyle);

        $underlyingStyle->newLine();
        $this->assertSame(2, $style->newLinesWritten());
    }

    public function testDetectsNewLineOnWrite()
    {
        $bufferedOutput = new BufferedOutput;

        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $style->write('Foo');
        $this->assertSame(0, $style->newLinesWritten());

        $style->write('Foo', true);
        $this->assertSame(1, $style->newLinesWritten());
    }

    public function testDetectsNewLineOnWriteln()
    {
        $bufferedOutput = new BufferedOutput;

        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $style->writeln('Foo');
        $this->assertSame(1, $style->newLinesWritten());
    }

    public function testDetectsNewLineOnlyOnOutput()
    {
        $bufferedOutput = new BufferedOutput;

        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $style->setVerbosity(OutputStyle::VERBOSITY_NORMAL);

        $style->writeln('Foo', OutputStyle::VERBOSITY_VERBOSE);
        $this->assertSame(1, $style->newLinesWritten());

        $style->setVerbosity(OutputStyle::VERBOSITY_VERBOSE);

        $style->writeln('Foo', OutputStyle::VERBOSITY_VERBOSE);
        $this->assertSame(1, $style->newLinesWritten());
    }

    public function testWriteConsumesGeneratorsOnceWithoutFabricatingNewLines(): void
    {
        $bufferedOutput = new BufferedOutput;
        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);
        $iterations = 0;

        $messages = (function () use (&$iterations) {
            ++$iterations;
            yield "first\n";

            ++$iterations;
            yield 'second';
        })();

        $style->write($messages);

        $this->assertSame(2, $iterations);
        $this->assertSame("first\nsecond", $bufferedOutput->fetch());
        $this->assertSame(0, $style->newLinesWritten());
    }

    public function testWritelnTracksTheLastGeneratorItem(): void
    {
        $bufferedOutput = new BufferedOutput;
        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $style->writeln((function () {
            yield 'first';
            yield "second\n\n";
        })());

        $this->assertSame("first\nsecond\n\n\n", $bufferedOutput->fetch());
        $this->assertSame(3, $style->newLinesWritten());
    }

    public function testEmptyNonNewlineWritesPreserveThePreviousState(): void
    {
        $style = new OutputStyle(new ArrayInput([]), new BufferedOutput);

        $style->write("first\n\n");
        $style->write('');
        $style->write((function () {
            yield from [];
        })());

        $this->assertSame(2, $style->newLinesWritten());
    }

    public function testOutputTypeAndVerbosityBitsAreInterpretedIndependently(): void
    {
        $bufferedOutput = new BufferedOutput(OutputStyle::VERBOSITY_NORMAL);
        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $style->write("visible\n\n", options: OutputStyle::OUTPUT_RAW | OutputStyle::VERBOSITY_NORMAL);
        $style->write('hidden', options: OutputStyle::OUTPUT_RAW | OutputStyle::VERBOSITY_VERBOSE);

        $this->assertSame("visible\n\n", $bufferedOutput->fetch());
        $this->assertSame(2, $style->newLinesWritten());
    }

    public function testFailedWritesDoNotChangeTheTrackedState(): void
    {
        $output = new class extends BufferedOutput {
            public bool $fail = false;

            protected function doWrite(string $message, bool $newline): void
            {
                if ($this->fail) {
                    throw new RuntimeException('Write failed.');
                }

                parent::doWrite($message, $newline);
            }
        };

        $style = new OutputStyle(new ArrayInput([]), $output);
        $style->write('visible');
        $output->fail = true;

        try {
            $style->writeln('hidden');
            $this->fail('Expected the output write to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Write failed.', $exception->getMessage());
        }

        $this->assertSame(0, $style->newLinesWritten());
    }
}
