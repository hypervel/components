<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Attributes\Signature;
use Hypervel\Console\Command;
use Hypervel\Console\ManuallyFailedException;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Command\DefaultSwooleFlagsCommand;
use Hypervel\Tests\Console\Command\FooCommand;
use Hypervel\Tests\Console\Command\FooExceptionCommand;
use Hypervel\Tests\Console\Command\FooExitCommand;
use Hypervel\Tests\Console\Command\FooProhibitableCommand;
use Hypervel\Tests\Console\Command\FooTraitCommand;
use Hypervel\Tests\Console\Command\SwooleFlagsCommand;
use Hypervel\Tests\Console\Command\Traits\Foo;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * @internal
 * @coversNothing
 */
class CommandTest extends TestCase
{
    public function testHookFlags()
    {
        $command = new DefaultSwooleFlagsCommand('test:demo');
        $this->assertSame(SWOOLE_HOOK_ALL, $command->getHookFlags());

        $command = new SwooleFlagsCommand('test:demo2');
        $this->assertSame(SWOOLE_HOOK_ALL | SWOOLE_HOOK_CURL, $command->getHookFlags());
    }

    public function testExitCodeWhenThrowException()
    {
        $output = m::mock(OutputStyle::class)->shouldIgnoreMissing();
        $application = m::mock(ConsoleApplication::class);
        $application->shouldReceive('getHelperSet');

        /** @var FooExceptionCommand $command */
        $command = new ClassInvoker(new FooExceptionCommand('foo'));
        $command->setApplication($application);
        $command->setOutput($output);
        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')->andReturn(true);

        $exitCode = $command->execute($input, $output);
        $this->assertSame(1, $exitCode);

        /** @var FooExitCommand $command */
        $command = new ClassInvoker(new FooExitCommand());
        $command->setApplication($application);
        $command->setOutput($output);
        $exitCode = $command->execute($input, $output);
        $this->assertSame(11, $exitCode);

        /** @var FooCommand $command */
        $command = new ClassInvoker(new FooCommand());
        $command->setApplication($application);
        $command->setOutput($output);
        $exitCode = $command->execute($input, $output);
        $this->assertSame(0, $exitCode);
    }

    public function testSetUpTraits()
    {
        $output = m::mock(OutputStyle::class)->shouldIgnoreMissing();
        $application = m::mock(ConsoleApplication::class);
        $application->shouldReceive('getHelperSet');
        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')->andReturnFalse();

        $command = new FooTraitCommand();
        $command->setApplication($application);
        $command->setOutput($output);
        $this->assertArrayHasKey(Foo::class, (fn () => $this->setUpTraits($input, $output))->call($command));
        $this->assertSame('foo', (fn () => $this->propertyFoo)->call($command));
    }

    public function testExitCodeWhenThrowExceptionInCoroutine()
    {
        $this->testExitCodeWhenThrowException();
    }

    public function testProhibitableCommand()
    {
        $application = m::mock(ConsoleApplication::class);
        $application->shouldReceive('getHelperSet');

        $output = m::mock(OutputStyle::class)->shouldIgnoreMissing();
        $command = new ClassInvoker(new FooProhibitableCommand());
        $command->setApplication($application);
        $command->setOutput($output);
        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')->andReturn(true);
        $result = $command->execute($input, $output);
        $this->assertSame(FooProhibitableCommand::SUCCESS, $result);

        FooProhibitableCommand::prohibit(true);

        $output = m::mock(OutputStyle::class)->shouldIgnoreMissing();
        $instance = new FooProhibitableCommand();
        $instance->setApplication($application);
        $instance->setOutput($output);
        (fn () => $this->components = new Factory($output))->call($instance);
        $command = new ClassInvoker($instance);
        $input = m::mock(InputInterface::class);
        $input->shouldReceive('getOption')->andReturn(true);
        $result = $command->execute($input, $output);
        $this->assertSame(FooProhibitableCommand::FAILURE, $result);

        FooProhibitableCommand::prohibit(false);
    }

    public function testFailWithNullThrowsManuallyFailedExceptionWithDefaultMessage(): void
    {
        $command = new CommandTestStubCommand();

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Command failed manually.');

        $command->fail(null);
    }

    public function testFailWithStringThrowsManuallyFailedExceptionWithMessage(): void
    {
        $command = new CommandTestStubCommand();

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Custom failure message');

        $command->fail('Custom failure message');
    }

    public function testFailWithThrowableRethrowsTheSameException(): void
    {
        $command = new CommandTestStubCommand();
        $exception = new RuntimeException('Original exception');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Original exception');

        $command->fail($exception);
    }

    public function testFailWithManuallyFailedExceptionRethrowsIt(): void
    {
        $command = new CommandTestStubCommand();
        $exception = new ManuallyFailedException('Pre-created failure');

        $this->expectException(ManuallyFailedException::class);
        $this->expectExceptionMessage('Pre-created failure');

        $command->fail($exception);
    }

    public function testCallingClassCommandResolveCommandViaApplicationResolution()
    {
        $command = new class extends Command {
            public function handle()
            {
            }
        };

        $app = m::mock(\Hypervel\Contracts\Foundation\Application::class);
        $command->setApp($app);

        $output = m::mock(OutputStyle::class)->shouldIgnoreMissing();
        $app->shouldReceive('make')->with(OutputStyle::class, m::any())->andReturn($output);
        $app->shouldReceive('make')->with(Factory::class, m::any())->andReturn(m::mock(Factory::class));
        $app->shouldReceive('bound')->andReturn(false);

        $app->shouldReceive('call')->with([$command, 'handle'])->andReturnUsing(function () use ($command, $app) {
            $commandCalled = m::mock(Command::class);

            $app->shouldReceive('make')->once()->with(Command::class)->andReturn($commandCalled);

            $commandCalled->shouldReceive('setApplication')->once()->with(null);
            $commandCalled->shouldReceive('run')->once();

            $command->call(Command::class);
        });
        $app->shouldReceive('runningUnitTests')->andReturn(true);

        $command->run(new ArrayInput([]), new NullOutput());
    }

    public function testGettingCommandArgumentsAndOptionsByClass()
    {
        $command = new class extends Command {
            public function handle()
            {
            }

            protected function getArguments(): array
            {
                return [
                    new InputArgument('argument-one', InputArgument::REQUIRED, 'first test argument'),
                    ['argument-two', InputArgument::OPTIONAL, 'a second test argument'],
                    [
                        'name' => 'argument-three',
                        'description' => 'a third test argument',
                        'mode' => InputArgument::OPTIONAL,
                        'default' => 'third-argument-default',
                    ],
                ];
            }

            protected function getOptions(): array
            {
                return [
                    new InputOption('option-one', 'o', InputOption::VALUE_OPTIONAL, 'first test option'),
                    ['option-two', 't', InputOption::VALUE_REQUIRED, 'second test option'],
                    [
                        'name' => 'option-three',
                        'description' => 'a third test option',
                        'mode' => InputOption::VALUE_OPTIONAL,
                        'default' => 'third-option-default',
                    ],
                ];
            }
        };

        $input = new ArrayInput([
            'argument-one' => 'test-first-argument',
            'argument-two' => 'test-second-argument',
            '--option-one' => 'test-first-option',
            '--option-two' => 'test-second-option',
        ]);
        $output = new NullOutput();

        $command->run($input, $output);

        $this->assertSame('test-first-argument', $command->argument('argument-one'));
        $this->assertSame('test-second-argument', $command->argument('argument-two'));
        $this->assertSame('third-argument-default', $command->argument('argument-three'));
        $this->assertSame('test-first-option', $command->option('option-one'));
        $this->assertSame('test-second-option', $command->option('option-two'));
        $this->assertSame('third-option-default', $command->option('option-three'));
    }

    public function testTheInputSetterOverwrite()
    {
        $input = m::mock(InputInterface::class);
        $input->shouldReceive('hasArgument')->once()->with('foo')->andReturn(false);

        $command = new CommandTestStubCommand();
        $command->setInput($input);

        $this->assertFalse($command->hasArgument('foo'));
    }

    public function testTheOutputSetterOverwrite()
    {
        $output = m::mock(OutputStyle::class);
        $output->shouldReceive('writeln')->once()->withArgs(function (...$args) {
            return $args[0] === '<info>foo</info>';
        });

        $command = new CommandTestStubCommand();
        $command->setOutput($output);

        $command->info('foo');
    }

    public function testSetHidden()
    {
        $command = new class extends Command {
            public function parentIsHidden(): bool
            {
                return parent::isHidden();
            }
        };

        $this->assertFalse($command->isHidden());
        $this->assertFalse($command->parentIsHidden());

        $command->setHidden(true);

        $this->assertTrue($command->isHidden());
        $this->assertTrue($command->parentIsHidden());
    }

    public function testHiddenProperty()
    {
        $command = new class extends Command {
            protected bool $hidden = true;

            public function parentIsHidden(): bool
            {
                return parent::isHidden();
            }
        };

        $this->assertTrue($command->isHidden());
        $this->assertTrue($command->parentIsHidden());

        $command->setHidden(false);

        $this->assertFalse($command->isHidden());
        $this->assertFalse($command->parentIsHidden());
    }

    public function testAliasesProperty()
    {
        $command = new class extends Command {
            protected ?string $name = 'foo:bar';

            protected array $aliases = ['bar:baz', 'baz:qux'];
        };

        $this->assertSame(['bar:baz', 'baz:qux'], $command->getAliases());
    }

    public function testChoiceIsSingleSelectByDefault()
    {
        $output = m::mock(OutputStyle::class);
        $output->shouldReceive('askQuestion')->once()->withArgs(function (ChoiceQuestion $question) {
            return $question->isMultiselect() === false;
        })->andReturn('yes');

        $command = new CommandTestStubCommand();
        $command->setOutput($output);

        $command->choice('Do you need further help?', ['yes', 'no']);
    }

    public function testChoiceWithMultiselect()
    {
        $output = m::mock(OutputStyle::class);
        $output->shouldReceive('askQuestion')->once()->withArgs(function (ChoiceQuestion $question) {
            return $question->isMultiselect() === true;
        })->andReturn(['option-1']);

        $command = new CommandTestStubCommand();
        $command->setOutput($output);

        $command->choice('Select all that apply.', ['option-1', 'option-2', 'option-3'], null, null, true);
    }

    public function testSignatureAttributeCanSetAliases()
    {
        $command = new CommandTestSignatureWithAliasesCommand();

        $this->assertSame('foo:bar', $command->getName());
        $this->assertSame(['bar:baz', 'baz:qux'], $command->getAliases());
    }
}

class CommandTestStubCommand extends Command
{
    protected ?string $signature = 'test:stub';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

#[Signature('foo:bar', aliases: ['bar:baz', 'baz:qux'])]
class CommandTestSignatureWithAliasesCommand extends Command
{
    public function handle()
    {
    }
}
