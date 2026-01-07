<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\ContainerCommandLoader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * @internal
 * @coversNothing
 */
class ContainerCommandLoaderTest extends TestCase
{
    public function testGetReturnsCommandFromContainer(): void
    {
        $command = new Command('test:command');
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with(TestCommand::class)
            ->willReturn($command);

        $loader = new ContainerCommandLoader($container, [
            'test:command' => TestCommand::class,
        ]);

        $this->assertSame($command, $loader->get('test:command'));
    }

    public function testGetThrowsForUnknownCommand(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $loader = new ContainerCommandLoader($container, [
            'test:command' => TestCommand::class,
        ]);

        $this->expectException(CommandNotFoundException::class);
        $this->expectExceptionMessage('Command "unknown:command" does not exist.');

        $loader->get('unknown:command');
    }

    public function testHasReturnsTrueForRegisteredCommand(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $loader = new ContainerCommandLoader($container, [
            'test:command' => TestCommand::class,
        ]);

        $this->assertTrue($loader->has('test:command'));
    }

    public function testHasReturnsFalseForUnknownCommand(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $loader = new ContainerCommandLoader($container, [
            'test:command' => TestCommand::class,
        ]);

        $this->assertFalse($loader->has('unknown:command'));
    }

    public function testHasReturnsFalseForEmptyString(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $loader = new ContainerCommandLoader($container, [
            'test:command' => TestCommand::class,
        ]);

        $this->assertFalse($loader->has(''));
    }

    public function testGetNamesReturnsAllCommandNames(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $loader = new ContainerCommandLoader($container, [
            'test:command' => TestCommand::class,
            'another:command' => AnotherTestCommand::class,
        ]);

        $this->assertSame(['test:command', 'another:command'], $loader->getNames());
    }

    public function testGetNamesReturnsEmptyArrayWhenNoCommands(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $loader = new ContainerCommandLoader($container, []);

        $this->assertSame([], $loader->getNames());
    }
}

class TestCommand extends Command
{
}

class AnotherTestCommand extends Command
{
}
