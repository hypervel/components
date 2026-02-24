<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Console\ContainerCommandLoader;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * @internal
 * @coversNothing
 */
class ConsoleApplicationResolveTest extends TestCase
{
    private function createApp(?Container $container = null): ConsoleApplication
    {
        $container ??= $this->createMock(Container::class);
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
        $container = $this->createMock(Container::class);
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

    // ---------------------------------------------------------------
    // CommandReplacer integration
    // ---------------------------------------------------------------

    public function testResolveSuppressesCommandMappedToNull()
    {
        $app = $this->createApp();

        $result = $app->resolve(StubSuppressedCommand::class);

        $this->assertNull($result);
        $this->assertArrayNotHasKey('info', $this->getCommandMap($app));
    }

    public function testResolveRenamesCommandAndPreservesAlias()
    {
        $app = $this->createApp();

        $app->resolve(StubRenamedCommand::class);

        $map = $this->getCommandMap($app);
        $this->assertArrayHasKey('make:command', $map);
        $this->assertArrayHasKey('gen:command', $map);
        $this->assertSame($map['make:command'], $map['gen:command']);
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

    /**
     * Read the private commandLoader property from Symfony's Application.
     */
    private function getCommandLoader(ConsoleApplication $app): ?ContainerCommandLoader
    {
        // Access the commandLoader via Symfony's private property.
        $ref = new ReflectionProperty(\Symfony\Component\Console\Application::class, 'commandLoader');

        return $ref->getValue($app);
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

/**
 * Uses a name that CommandReplacer suppresses (mapped to null).
 */
class StubSuppressedCommand extends Command
{
    protected ?string $name = 'info';

    public function handle(): void
    {
    }
}

/**
 * Uses a name that CommandReplacer renames (gen:command → make:command).
 */
class StubRenamedCommand extends Command
{
    protected ?string $name = 'gen:command';

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
