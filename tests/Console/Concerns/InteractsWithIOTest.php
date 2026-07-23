<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Concerns;

use Generator;
use Hypervel\Console\Command;
use Hypervel\Console\Concerns\InteractsWithIO;
use Hypervel\Console\OutputStyle;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;

class InteractsWithIOTest extends TestCase
{
    #[DataProvider('iterableDataProvider')]
    public function testWithProgressBarIterable($iterable)
    {
        $command = new CommandInteractsWithIO;
        $bufferedOutput = new BufferedOutput;
        $output = m::mock(OutputStyle::class, [new ArgvInput, $bufferedOutput])->makePartial();
        $command->setOutput($output);

        $output->shouldReceive('createProgressBar')
            ->once()
            ->with(count($iterable))
            ->andReturnUsing(function ($steps) use ($bufferedOutput) {
                // we can't mock ProgressBar because it's final, so return a real one
                return new ProgressBar($bufferedOutput, $steps);
            });

        $calledTimes = 0;
        $result = $command->withProgressBar($iterable, function ($value, $bar, $key) use (&$calledTimes, $iterable) {
            $this->assertInstanceOf(ProgressBar::class, $bar);
            $this->assertSame(array_values($iterable)[$calledTimes], $value);
            $this->assertSame(array_keys($iterable)[$calledTimes], $key);
            ++$calledTimes;
        });

        $this->assertSame(count($iterable), $calledTimes);
        $this->assertSame($iterable, $result);
    }

    public static function iterableDataProvider(): Generator
    {
        yield [['a', 'b', 'c']];

        yield [['foo' => 'a', 'bar' => 'b', 'baz' => 'c']];
    }

    public function testWithProgressBarInteger()
    {
        $command = new CommandInteractsWithIO;
        $bufferedOutput = new BufferedOutput;
        $output = m::mock(OutputStyle::class, [new ArgvInput, $bufferedOutput])->makePartial();
        $command->setOutput($output);

        $totalSteps = 5;

        $output->shouldReceive('createProgressBar')
            ->once()
            ->with($totalSteps)
            ->andReturnUsing(function ($steps) use ($bufferedOutput) {
                // we can't mock ProgressBar because it's final, so return a real one
                return new ProgressBar($bufferedOutput, $steps);
            });

        $called = false;
        $command->withProgressBar($totalSteps, function ($bar) use (&$called) {
            $this->assertInstanceOf(ProgressBar::class, $bar);
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testWithProgressBarSupportsGeneratorsWithoutPrecounting(): void
    {
        $command = new CommandInteractsWithIO;
        $bufferedOutput = new BufferedOutput;
        $output = m::mock(OutputStyle::class, [new ArgvInput, $bufferedOutput])->makePartial();
        $command->setOutput($output);

        $generator = (function () {
            yield 'first' => 'a';
            yield 'second' => 'b';
        })();

        $output->shouldReceive('createProgressBar')
            ->once()
            ->with(0)
            ->andReturnUsing(fn (int $steps): ProgressBar => new ProgressBar($bufferedOutput, $steps));

        $values = [];
        $result = $command->withProgressBar($generator, function (string $value, ProgressBar $bar, string $key) use (&$values): void {
            $this->assertSame(0, $bar->getMaxSteps());
            $values[$key] = $value;
        });

        $this->assertSame(['first' => 'a', 'second' => 'b'], $values);
        $this->assertSame($generator, $result);
    }

    public function testAskWithCompletionAcceptsGeneratorValues(): void
    {
        $command = new CommandInteractsWithIO;
        $output = m::mock(OutputStyle::class);
        $command->setOutput($output);

        $choices = (function () {
            yield 'alpha';
            yield 'bravo';
        })();

        $output->shouldReceive('askQuestion')
            ->once()
            ->with(m::on(function (Question $question): bool {
                $this->assertSame(['alpha', 'bravo'], $question->getAutocompleterValues());

                return true;
            }))
            ->andReturn('bravo');

        $this->assertSame('bravo', $command->anticipate('Choose', $choices));
    }
}

class CommandInteractsWithIO extends Command
{
    use InteractsWithIO;
}
