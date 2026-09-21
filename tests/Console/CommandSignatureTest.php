<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Command;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

class CommandSignatureTest extends TestCase
{
    #[DataProvider('commands')]
    public function testCommandSignatureMatchesFixture(string $class, array $expected): void
    {
        $this->assertTrue(
            class_exists($class),
            "Command class [{$class}] no longer exists. Update tests/Console/Fixtures/command_signatures.php."
        );

        $command = $this->makeCommandWithoutDependencies($class);

        $this->assertSame($expected['name'], $command->getName(), "Command name changed for [{$class}].");
        $this->assertSame($expected['aliases'], $command->getAliases(), "Command aliases changed for [{$class}].");
        $this->assertSame($expected['hidden'], $command->isHidden(), "Command visibility changed for [{$class}].");

        $definition = $command->getDefinition();

        $arguments = [];

        foreach ($definition->getArguments() as $argument) {
            $arguments[] = [
                'name' => $argument->getName(),
                'mode' => $argument->isRequired() ? 'required' : 'optional',
                'isArray' => $argument->isArray(),
                'default' => $argument->getDefault(),
                'description' => $argument->getDescription(),
            ];
        }

        $options = [];

        foreach ($definition->getOptions() as $option) {
            $options[] = [
                'name' => $option->getName(),
                'shortcut' => $option->getShortcut(),
                'negatable' => $option->isNegatable(),
                'valueRequired' => $option->isValueRequired(),
                'valueOptional' => $option->isValueOptional(),
                'isArray' => $option->isArray(),
                'acceptValue' => $option->acceptValue(),
                'default' => $option->getDefault(),
                'description' => $option->getDescription(),
            ];
        }

        $this->assertSame($expected['arguments'], $arguments, "Command arguments changed for [{$class}].");
        $this->assertSame($expected['options'], $options, "Command options changed for [{$class}].");
    }

    /**
     * Provide the recorded command signatures.
     */
    public static function commands(): array
    {
        $commands = require __DIR__ . '/Fixtures/command_signatures.php';

        // Record the shared option once, independently of the production definition.
        $dispatcherOption = [
            'name' => 'disable-event-dispatcher',
            'shortcut' => null,
            'negatable' => false,
            'valueRequired' => false,
            'valueOptional' => false,
            'isArray' => false,
            'acceptValue' => false,
            'default' => false,
            'description' => 'Disable the event dispatcher',
        ];

        $cases = [];

        foreach ($commands as $class => $expected) {
            $expected = [
                'aliases' => $expected['aliases'] ?? [],
                'hidden' => $expected['hidden'] ?? false,
                'arguments' => $expected['arguments'] ?? [],
                'options' => $expected['options'] ?? [],
                ...$expected,
            ];

            array_splice(
                $expected['options'],
                $expected['dispatcherOptionOffset'] ?? count($expected['options']),
                0,
                [$dispatcherOption]
            );

            $cases[$class] = [$class, $expected];
        }

        return $cases;
    }

    /**
     * Create a command definition without resolving its dependencies.
     */
    protected function makeCommandWithoutDependencies(string $class): Command
    {
        $reflection = new ReflectionClass($class);

        $instance = $reflection->newInstanceWithoutConstructor();

        (new ReflectionMethod(Command::class, '__construct'))->invoke($instance);

        return $instance;
    }
}
