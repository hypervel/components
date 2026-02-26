<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

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
use Symfony\Component\Console\Input\InputInterface;

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
}

class CommandTestStubCommand extends Command
{
    protected ?string $signature = 'test:stub';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
