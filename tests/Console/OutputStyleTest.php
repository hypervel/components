<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\OutputStyle;
use Hypervel\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 * @coversNothing
 */
class OutputStyleTest extends TestCase
{
    public function testDetectsNewLine()
    {
        $bufferedOutput = new BufferedOutput();

        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $this->assertSame(1, $style->newLinesWritten());

        $style->newLine();
        $this->assertSame(2, $style->newLinesWritten());
    }

    public function testDetectsNewLineOnUnderlyingOutput()
    {
        $bufferedOutput = new BufferedOutput();

        $underlyingStyle = new OutputStyle(new ArrayInput([]), $bufferedOutput);
        $style = new OutputStyle(new ArrayInput([]), $underlyingStyle);

        $underlyingStyle->newLine();
        $this->assertSame(2, $style->newLinesWritten());
    }

    public function testDetectsNewLineOnWrite()
    {
        $bufferedOutput = new BufferedOutput();

        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $style->write('Foo');
        $this->assertSame(0, $style->newLinesWritten());

        $style->write('Foo', true);
        $this->assertSame(1, $style->newLinesWritten());
    }

    public function testDetectsNewLineOnWriteln()
    {
        $bufferedOutput = new BufferedOutput();

        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $style->writeln('Foo');
        $this->assertSame(1, $style->newLinesWritten());
    }

    public function testDetectsNewLineOnlyOnOutput()
    {
        $bufferedOutput = new BufferedOutput();

        $style = new OutputStyle(new ArrayInput([]), $bufferedOutput);

        $style->setVerbosity(OutputStyle::VERBOSITY_NORMAL);

        $style->writeln('Foo', OutputStyle::VERBOSITY_VERBOSE);
        $this->assertSame(1, $style->newLinesWritten());

        $style->setVerbosity(OutputStyle::VERBOSITY_VERBOSE);

        $style->writeln('Foo', OutputStyle::VERBOSITY_VERBOSE);
        $this->assertSame(1, $style->newLinesWritten());
    }
}
