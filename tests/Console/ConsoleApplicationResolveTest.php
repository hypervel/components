<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Attributes\Aliases;
use Hypervel\Console\Attributes\Signature;
use Hypervel\Console\Command;
use Hypervel\Console\ContainerCommandLoader;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Fixtures\FakeCommandWithArrayInputPrompting;
use Hypervel\Tests\Console\Fixtures\FakeCommandWithInputPrompting;
use Mockery as m;
use ReflectionProperty;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ConsoleApplicationResolveTest extends TestCase
{
    private function createApp(?Application $container = null): ConsoleApplication
    {
        $container ??= $this->createStub(Application::class);
        $dispatcher = $this->createStub(Dispatcher::class);

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

    public function testResolveLazilyRegistersCommandWithSignatureAttribute()
    {
        $app = $this->createApp();

        $result = $app->resolve(StubSignatureAttributeCommand::class);

        $this->assertNull($result);
        $this->assertArrayHasKey('test:signed-attribute', $this->getCommandMap($app));
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

        $command = new StubAttributedCommand;
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

    public function testAddCommandSetsHypervelOnHypervelCommands()
    {
        $artisan = $this->getMockConsole(['addToParent']);

        $command = m::mock(Command::class);
        $command->shouldReceive('setHypervel')->once()->with(m::type(Application::class));
        $artisan->expects($this->once())->method('addToParent')->with($command)->willReturn($command);

        $result = $artisan->add($command);

        $this->assertSame($command, $result);
    }

    public function testAddCommandDoesNotSetHypervelOnSymfonyCommands()
    {
        $artisan = $this->getMockConsole(['addToParent']);

        $command = m::mock(SymfonyCommand::class);
        $command->shouldNotReceive('setHypervel');
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

    public function testResolveRegistersPropertyAliasesInCommandMap()
    {
        $app = $this->createApp();

        $app->resolve(StubCommandWithPropertyAlias::class);

        $map = $this->getCommandMap($app);
        $this->assertArrayHasKey('alias-test:prop', $map);
        $this->assertArrayHasKey('alias-test:prop-alias', $map);
    }

    public function testPropertyAliasResolvesDirectlyWithoutPrimaryName()
    {
        $app = $this->createApp($this->app);
        $app->resolve(StubCommandWithPropertyAlias::class);
        $app->setContainerCommandLoader();

        // Access alias DIRECTLY — never resolve the primary name first.
        $this->assertInstanceOf(StubCommandWithPropertyAlias::class, $app->get('alias-test:prop-alias'));
    }

    public function testSignatureCommandWithAliasesResolvesDirectlyByAlias()
    {
        $app = $this->createApp($this->app);
        $app->resolve(StubSignatureWithAliasCommand::class);
        $app->setContainerCommandLoader();

        // Access alias DIRECTLY — never resolve the primary name first.
        $this->assertInstanceOf(StubSignatureWithAliasCommand::class, $app->get('test:signed-alias'));
    }

    public function testSignatureAttributeCommandWithAliasesResolvesDirectlyByAlias()
    {
        $app = $this->createApp($this->app);
        $app->resolve(StubSignatureAttributeCommand::class);
        $app->setContainerCommandLoader();

        // Access alias DIRECTLY — never resolve the primary name first.
        $this->assertInstanceOf(StubSignatureAttributeCommand::class, $app->get('test:signed-attribute-alias'));
    }

    public function testAliasesAttributeCommandResolvesDirectlyByAlias(): void
    {
        $app = $this->createApp($this->app);
        $app->resolve(StubAliasesAttributeCommand::class);
        $app->setContainerCommandLoader();

        $this->assertInstanceOf(StubAliasesAttributeCommand::class, $app->get('test:aliases-attribute-alias'));
    }

    public function testAliasesAttributeOverridesSignatureAliasesInCommandMap(): void
    {
        $app = $this->createApp();

        $app->resolve(StubAliasesAttributeOverridesSignatureCommand::class);

        $map = $this->getCommandMap($app);
        $this->assertArrayHasKey('test:aliases-attribute', $map);
        $this->assertArrayHasKey('test:aliases-attribute-override', $map);
        $this->assertArrayNotHasKey('test:aliases-attribute-ignored', $map);
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
        $output = new BufferedOutput;

        $artisan->addCommands([$command = new FakeCommandWithInputPrompting]);
        $command->setHypervel($this->app);

        $exitCode = $artisan->call('fake-command-for-testing', [], $output);

        $this->assertSame(0, $exitCode);
        $this->assertSame("foo\n", $output->fetch());
    }

    public function testCommandInputDoesntPromptWhenRequiredArgumentIsPassed()
    {
        $artisan = $this->createApp($this->app);
        $output = new BufferedOutput;

        $artisan->addCommands([new FakeCommandWithInputPrompting]);

        $exitCode = $artisan->call('fake-command-for-testing', [
            'name' => 'bar',
        ], $output);

        $this->assertSame(0, $exitCode);
        $this->assertSame("bar\n", $output->fetch());
    }

    public function testCommandInputPromptsWhenRequiredArgumentsAreMissing()
    {
        $artisan = $this->createApp($this->app);
        $output = new BufferedOutput;

        $artisan->addCommands([$command = new FakeCommandWithArrayInputPrompting]);
        $command->setHypervel($this->app);

        $exitCode = $artisan->call('fake-command-for-testing-array', [], $output);

        $this->assertSame(0, $exitCode);
        $this->assertSame("foo\n", $output->fetch());
    }

    public function testCommandInputDoesntPromptWhenRequiredArgumentsArePassed()
    {
        $artisan = $this->createApp($this->app);
        $output = new BufferedOutput;

        $artisan->addCommands([new FakeCommandWithArrayInputPrompting]);

        $exitCode = $artisan->call('fake-command-for-testing-array', [
            'names' => ['bar', 'baz'],
        ], $output);

        $this->assertSame(0, $exitCode);
        $this->assertSame("bar,baz\n", $output->fetch());
    }

    public function testCallMethodCanCallArtisanCommandUsingCommandClassObject()
    {
        $artisan = $this->createApp($this->app);
        $output = new BufferedOutput;

        $artisan->addCommands([$command = new FakeCommandWithInputPrompting]);
        $command->setHypervel($this->app);

        $exitCode = $artisan->call($command, [], $output);

        $this->assertSame(0, $exitCode);
        $this->assertSame("foo\n", $output->fetch());
    }

    public function testConcurrentCallsUseIsolatedCommandInstances()
    {
        $artisan = $this->createApp($this->app);
        $artisan->resolve(StubStatefulCommand::class);
        $artisan->setContainerCommandLoader();

        $outputA = new BufferedOutput;
        $outputB = new BufferedOutput;

        [$exitCodeA, $exitCodeB] = parallel([
            fn () => $artisan->call('test:stateful', [
                'value' => 'alpha',
                '--sleep' => 5000,
            ], $outputA),
            function () use ($artisan, $outputB) {
                usleep(2500);

                return $artisan->call('test:stateful', [
                    'value' => 'bravo',
                    '--sleep' => 0,
                ], $outputB);
            },
        ]);

        $this->assertSame(0, $exitCodeA);
        $this->assertSame(0, $exitCodeB);
        $this->assertSame("alpha\n", $outputA->fetch());
        $this->assertSame("bravo\n", $outputB->fetch());
    }

    public function testConcurrentNestedCallsUseIsolatedCommandInstances()
    {
        $artisan = $this->createApp($this->app);
        $artisan->resolve(StubNestedCallerCommand::class);
        $artisan->resolve(StubStatefulCommand::class);
        $artisan->setContainerCommandLoader();

        $outputA = new BufferedOutput;
        $outputB = new BufferedOutput;

        [$exitCodeA, $exitCodeB] = parallel([
            fn () => $artisan->call('test:nested-caller', [
                'value' => 'alpha',
                '--sleep' => 5000,
            ], $outputA),
            function () use ($artisan, $outputB) {
                usleep(2500);

                return $artisan->call('test:nested-caller', [
                    'value' => 'bravo',
                    '--sleep' => 0,
                ], $outputB);
            },
        ]);

        $this->assertSame(0, $exitCodeA);
        $this->assertSame(0, $exitCodeB);
        $this->assertSame("alpha\n", $outputA->fetch());
        $this->assertSame("bravo\n", $outputB->fetch());
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

#[Signature('test:signed-attribute {--option}', aliases: ['test:signed-attribute-alias'])]
class StubSignatureAttributeCommand extends Command
{
    public function handle(): void
    {
    }
}

#[Signature('test:aliases-attribute {--option}')]
#[Aliases(['test:aliases-attribute-alias'])]
class StubAliasesAttributeCommand extends Command
{
    public function handle(): void
    {
    }
}

#[Signature('test:aliases-attribute {--option}', aliases: ['test:aliases-attribute-ignored'])]
#[Aliases(['test:aliases-attribute-override'])]
class StubAliasesAttributeOverridesSignatureCommand extends Command
{
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

class StubSignatureWithAliasCommand extends Command
{
    protected ?string $signature = 'test:signed-primary {--option}';

    protected array $aliases = ['test:signed-alias'];

    public function handle(): void
    {
    }
}

class StubStatefulCommand extends Command
{
    protected ?string $signature = 'test:stateful {value} {--sleep=0}';

    public function handle(): int
    {
        usleep((int) $this->option('sleep'));

        $this->line((string) $this->argument('value'));

        return self::SUCCESS;
    }
}

class StubNestedCallerCommand extends Command
{
    protected ?string $signature = 'test:nested-caller {value} {--sleep=0}';

    public function handle(): int
    {
        return $this->call('test:stateful', [
            'value' => $this->argument('value'),
            '--sleep' => $this->option('sleep'),
        ]);
    }
}
