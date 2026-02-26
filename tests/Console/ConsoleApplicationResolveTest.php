<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Console\ContainerCommandLoader;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Fixtures\FakeCommandWithArrayInputPrompting;
use Hypervel\Tests\Console\Fixtures\FakeCommandWithInputPrompting;
use Mockery as m;
use ReflectionProperty;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class ConsoleApplicationResolveTest extends TestCase
{
    private function createApp(?Application $container = null): ConsoleApplication
    {
        $container ??= $this->createMock(Application::class);
        $dispatcher = $this->createMock(Dispatcher::class);

        return new ConsoleApplication($container, $dispatcher, '1.0');
    }

    /**
     * Read the protected commandMap property without triggering lazy resolution.
     */
    private function getCommandMap(ConsoleApplication $app): array
    {
        return (new ReflectionProperty(ConsoleApplication::class, 'commandMap'))->getValue($app);
    }

    // ---------------------------------------------------------------
    // extractCommandName (tested indirectly through resolve)
    // ---------------------------------------------------------------

    public function testResolveLazilyRegistersCommandWithAsCommandAttribute()
    {
        $app = $this->createApp();

        $result = $app->resolve(StubAttributedCommand::class);

        $this->assertNull($result);
        $this->assertArrayHasKey('test:attributed', $this->getCommandMap($app));
    }

    public function testResolveLazilyRegistersCommandWithSignatureProperty()
    {
        $app = $this->createApp();

        $result = $app->resolve(StubSignatureCommand::class);

        $this->assertNull($result);
        $this->assertArrayHasKey('test:signed', $this->getCommandMap($app));
    }

    public function testResolveLazilyRegistersCommandWithNameProperty()
    {
        $app = $this->createApp();

        $result = $app->resolve(StubNamedCommand::class);

        $this->assertNull($result);
        $this->assertArrayHasKey('test:named', $this->getCommandMap($app));
    }

    public function testResolveRegistersAllPipeAliases()
    {
        $app = $this->createApp();

        $app->resolve(StubAliasedCommand::class);

        $map = $this->getCommandMap($app);
        $this->assertArrayHasKey('test:primary', $map);
        $this->assertArrayHasKey('test:alias', $map);
    }

    public function testResolveEagerlyResolvesCommandWithoutStaticName()
    {
        $command = new SymfonyCommand('test:dynamic');
        $container = $this->createMock(Application::class);
        $container->expects($this->once())
            ->method('make')
            ->with(StubDynamicCommand::class)
            ->willReturn($command);

        $app = $this->createApp($container);
        $result = $app->resolve(StubDynamicCommand::class);

        $this->assertInstanceOf(SymfonyCommand::class, $result);
        $this->assertArrayNotHasKey('test:dynamic', $this->getCommandMap($app));
    }

    public function testAsCommandAttributeTakesPriorityOverSignature()
    {
        $app = $this->createApp();

        $app->resolve(StubAttributeOverridesSignatureCommand::class);

        $map = $this->getCommandMap($app);
        $this->assertArrayHasKey('test:from-attribute', $map);
        $this->assertArrayNotHasKey('test:from-signature', $map);
    }

    public function testResolveEagerlyAddsCommandInstance()
    {
        $app = $this->createApp($this->app);

        $command = new StubAttributedCommand();
        $result = $app->resolve($command);

        $this->assertSame($command, $result);
        $this->assertSame($command, $app->get('test:attributed'));
    }

    // ---------------------------------------------------------------
    // Loader refresh
    // ---------------------------------------------------------------

    public function testResolveRefreshesLoaderWhenAlreadySet()
    {
        $app = $this->createApp();
        $app->setContainerCommandLoader();

        // Loader was set with an empty commandMap.
        $loaderBefore = $this->getCommandLoader($app);
        $this->assertFalse($loaderBefore->has('test:late'));

        // Resolve a new lazy command after the loader was set.
        $app->resolve(StubLateCommand::class);

        // The loader should have been refreshed with the new entry.
        $loaderAfter = $this->getCommandLoader($app);
        $this->assertTrue($loaderAfter->has('test:late'));
        $this->assertNotSame($loaderBefore, $loaderAfter);
    }

    public function testResolveDoesNotRefreshLoaderWhenNotYetSet()
    {
        $app = $this->createApp();

        // No setContainerCommandLoader() — the commandLoaderSet flag is false.
        $app->resolve(StubSignatureCommand::class);

        $this->assertArrayHasKey('test:signed', $this->getCommandMap($app));
        // The loader wasn't set, so no refresh happened (no loader to check).
        $this->assertNull($this->getCommandLoader($app));
    }

    // ---------------------------------------------------------------
    // addCommand (container propagation)
    // ---------------------------------------------------------------

    public function testAddCommandSetsAppOnHypervelCommands()
    {
        $artisan = $this->getMockConsole(['addToParent']);

        $command = m::mock(Command::class);
        $command->shouldReceive('setApp')->once()->with(m::type(Application::class));
        $artisan->expects($this->once())->method('addToParent')->with($command)->willReturn($command);

        $result = $artisan->add($command);

        $this->assertSame($command, $result);
    }

    public function testAddCommandDoesNotSetAppOnSymfonyCommands()
    {
        $artisan = $this->getMockConsole(['addToParent']);

        $command = m::mock(SymfonyCommand::class);
        $command->shouldNotReceive('setApp');
        $artisan->expects($this->once())->method('addToParent')->with($command)->willReturn($command);

        $result = $artisan->add($command);

        $this->assertSame($command, $result);
    }

    // ---------------------------------------------------------------
    // Alias resolution via AsCommand attribute and $aliases property
    // ---------------------------------------------------------------

    public function testResolvingCommandsWithAliasViaAttribute()
    {
        $app = $this->createApp($this->app);
        $app->resolve(StubCommandWithAttributeAlias::class);
        $app->setContainerCommandLoader();

        $this->assertInstanceOf(StubCommandWithAttributeAlias::class, $app->get('alias-test:attr'));
        $this->assertInstanceOf(StubCommandWithAttributeAlias::class, $app->get('alias-test:attr-alias'));
        $this->assertArrayHasKey('alias-test:attr', $app->all());
        $this->assertArrayHasKey('alias-test:attr-alias', $app->all());
    }

    public function testResolvingCommandsWithAliasViaProperty()
    {
        $app = $this->createApp($this->app);
        $app->resolve(StubCommandWithPropertyAlias::class);
        $app->setContainerCommandLoader();

        $this->assertInstanceOf(StubCommandWithPropertyAlias::class, $app->get('alias-test:prop'));
        $this->assertInstanceOf(StubCommandWithPropertyAlias::class, $app->get('alias-test:prop-alias'));
        $this->assertArrayHasKey('alias-test:prop', $app->all());
        $this->assertArrayHasKey('alias-test:prop-alias', $app->all());
    }

    public function testResolvingCommandsWithNoAliasViaAttribute()
    {
        $app = $this->createApp($this->app);
        $app->resolve(StubAttributedCommand::class);
        $app->setContainerCommandLoader();

        $this->assertInstanceOf(StubAttributedCommand::class, $app->get('test:attributed'));

        try {
            $app->get('some-nonexistent-alias');
            $this->fail();
        } catch (Throwable $e) {
            $this->assertInstanceOf(CommandNotFoundException::class, $e);
        }
    }

    public function testResolvingCommandsWithNoAliasViaProperty()
    {
        $app = $this->createApp($this->app);
        $app->resolve(StubCommandWithoutPropertyAlias::class);
        $app->setContainerCommandLoader();

        $this->assertInstanceOf(StubCommandWithoutPropertyAlias::class, $app->get('alias-test:no-alias'));

        try {
            $app->get('some-nonexistent-alias');
            $this->fail();
        } catch (Throwable $e) {
            $this->assertInstanceOf(CommandNotFoundException::class, $e);
        }
    }

    // ---------------------------------------------------------------
    // Application::call()
    // ---------------------------------------------------------------

    public function testCallStringAndArrayInputProduceSameResult()
    {
        $app = $this->createApp(
            m::mock(Application::class, ['version' => '1.0']),
        );

        $codeOfCallingArrayInput = $app->call('help', [
            '--raw' => true,
            '--format' => 'txt',
            '--no-interaction' => true,
            '--env' => 'testing',
        ]);

        $outputOfCallingArrayInput = $app->output();

        $codeOfCallingStringInput = $app->call(
            'help --raw --format=txt --no-interaction --env=testing'
        );

        $outputOfCallingStringInput = $app->output();

        $this->assertSame($codeOfCallingArrayInput, $codeOfCallingStringInput);
        $this->assertSame($outputOfCallingArrayInput, $outputOfCallingStringInput);
    }

    // ---------------------------------------------------------------
    // PromptsForMissingInput
    // ---------------------------------------------------------------

    public function testCommandInputPromptsWhenRequiredArgumentIsMissing()
    {
        $artisan = $this->createApp($this->app);

        $artisan->addCommands([$command = new FakeCommandWithInputPrompting()]);
        $command->setApp($this->app);

        $exitCode = $artisan->call('fake-command-for-testing');

        $this->assertTrue($command->prompted);
        $this->assertSame('foo', $command->argument('name'));
        $this->assertSame(0, $exitCode);
    }

    public function testCommandInputDoesntPromptWhenRequiredArgumentIsPassed()
    {
        $artisan = $this->createApp($this->app);

        $artisan->addCommands([$command = new FakeCommandWithInputPrompting()]);

        $exitCode = $artisan->call('fake-command-for-testing', [
            'name' => 'foo',
        ]);

        $this->assertFalse($command->prompted);
        $this->assertSame('foo', $command->argument('name'));
        $this->assertSame(0, $exitCode);
    }

    public function testCommandInputPromptsWhenRequiredArgumentsAreMissing()
    {
        $artisan = $this->createApp($this->app);

        $artisan->addCommands([$command = new FakeCommandWithArrayInputPrompting()]);
        $command->setApp($this->app);

        $exitCode = $artisan->call('fake-command-for-testing-array');

        $this->assertTrue($command->prompted);
        $this->assertSame(['foo'], $command->argument('names'));
        $this->assertSame(0, $exitCode);
    }

    public function testCommandInputDoesntPromptWhenRequiredArgumentsArePassed()
    {
        $artisan = $this->createApp($this->app);

        $artisan->addCommands([$command = new FakeCommandWithArrayInputPrompting()]);

        $exitCode = $artisan->call('fake-command-for-testing-array', [
            'names' => ['foo', 'bar', 'baz'],
        ]);

        $this->assertFalse($command->prompted);
        $this->assertSame(['foo', 'bar', 'baz'], $command->argument('names'));
        $this->assertSame(0, $exitCode);
    }

    public function testCallMethodCanCallArtisanCommandUsingCommandClassObject()
    {
        $artisan = $this->createApp($this->app);

        $artisan->addCommands([$command = new FakeCommandWithInputPrompting()]);
        $command->setApp($this->app);

        $exitCode = $artisan->call($command);

        $this->assertTrue($command->prompted);
        $this->assertSame('foo', $command->argument('name'));
        $this->assertSame(0, $exitCode);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Read the private commandLoader property from Symfony's Application.
     */
    private function getCommandLoader(ConsoleApplication $app): ?ContainerCommandLoader
    {
        // Access the commandLoader via Symfony's private property.
        $ref = new ReflectionProperty(\Symfony\Component\Console\Application::class, 'commandLoader');

        return $ref->getValue($app);
    }

    /**
     * Create a mock Application with specific methods overridden.
     */
    private function getMockConsole(array $methods): ConsoleApplication
    {
        $app = m::mock(Application::class, ['version' => '1.0']);
        $events = m::mock(Dispatcher::class, ['dispatch' => null]);

        return $this->getMockBuilder(ConsoleApplication::class)
            ->onlyMethods($methods)
            ->setConstructorArgs([$app, $events, '1.0'])
            ->getMock();
    }
}

// -- Test stub commands ---------------------------------------------------

#[AsCommand(name: 'test:attributed')]
class StubAttributedCommand extends Command
{
    public function handle(): void
    {
    }
}

class StubSignatureCommand extends Command
{
    protected ?string $signature = 'test:signed {--option}';

    public function handle(): void
    {
    }
}

class StubNamedCommand extends Command
{
    protected ?string $name = 'test:named';

    public function handle(): void
    {
    }
}

class StubAliasedCommand extends Command
{
    protected ?string $name = 'test:primary|test:alias';

    public function handle(): void
    {
    }
}

/**
 * Command whose name can only be determined at construction time.
 */
class StubDynamicCommand extends SymfonyCommand
{
    public function __construct()
    {
        parent::__construct('test:dynamic');
    }
}

#[AsCommand(name: 'test:from-attribute')]
class StubAttributeOverridesSignatureCommand extends Command
{
    protected ?string $signature = 'test:from-signature {--option}';

    public function handle(): void
    {
    }
}

#[AsCommand(name: 'test:late')]
class StubLateCommand extends Command
{
    public function handle(): void
    {
    }
}

#[AsCommand(name: 'alias-test:attr', aliases: ['alias-test:attr-alias'])]
class StubCommandWithAttributeAlias extends Command
{
    public function handle(): void
    {
    }
}

class StubCommandWithPropertyAlias extends Command
{
    protected ?string $name = 'alias-test:prop';

    protected array $aliases = ['alias-test:prop-alias'];

    public function handle(): void
    {
    }
}

class StubCommandWithoutPropertyAlias extends Command
{
    protected ?string $name = 'alias-test:no-alias';

    public function handle(): void
    {
    }
}
