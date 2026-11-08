<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Internal;

use Hypervel\Console\Application;
use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Console\ClosureCommand;
use Hypervel\PHPStan\Concerns\HasContainer;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Type;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

use function app;
use function array_key_exists;
use function is_a;
use function spl_object_id;

/** @internal */
#[AutowiredService]
class ConsoleApplicationResolver
{
    use HasContainer;

    private ?Application $application = null;

    /** @var null|array<string, SymfonyCommand> */
    private ?array $commands = null;

    /** @var array<string, array<int, SymfonyCommand>> */
    private array $commandsByClass = [];

    /**
     * Find the registered commands that are instances of the given class.
     *
     * @return array<int, SymfonyCommand> keyed by object ID
     */
    public function findCommands(ClassReflection $classReflection): array
    {
        $className = $classReflection->getName();

        if (array_key_exists($className, $this->commandsByClass)) {
            return $this->commandsByClass[$className];
        }

        // The base command stands for every command, and closure commands share one class
        // across unrelated signatures, so neither identifies a definition.
        if (! $classReflection->is(Command::class) || $className === Command::class || $className === ClosureCommand::class) {
            return $this->commandsByClass[$className] = [];
        }

        $commands = [];

        foreach ($this->getCommands() as $command) {
            $id = spl_object_id($command);

            // Each alias lists a command again, under a key that need not be its own name.
            if (isset($commands[$id]) || ! is_a($command, $className)) {
                continue;
            }

            // Upstream merges the application definition only for single-name lookups, so bulk
            // shapes depended on analysis order. Merge it once, as run() does before binding input.
            $command->mergeApplicationDefinition();

            $commands[$id] = $command;
        }

        return $this->commandsByClass[$className] = $commands;
    }

    /**
     * Find the registered commands the given receiver type can be.
     *
     * @return array<int, SymfonyCommand> keyed by object ID
     */
    public function findCommandsForType(Type $type): array
    {
        $commands = [];

        foreach ($type->getObjectClassReflections() as $classReflection) {
            $classCommands = $this->findCommands($classReflection);

            // An alternative with no known definition could define any name, so the other
            // alternatives cannot describe the receiver.
            if ($classCommands === []) {
                return [];
            }

            $commands += $classCommands;
        }

        return $commands;
    }

    /**
     * Get the commands registered with the console.
     *
     * Commands registered through the console kernel (`withCommands()` classes and discovered
     * paths, and `command()` closures) are only added to the kernel's own Artisan application,
     * so ask the kernel.
     *
     * @return array<string, SymfonyCommand>
     */
    private function getCommands(): array
    {
        if ($this->commands !== null) {
            return $this->commands;
        }

        $kernel = $this->resolve(Kernel::class);

        if ($kernel instanceof Kernel) {
            try {
                return $this->commands = $kernel->all();
            } catch (Throwable) {
                // A command that cannot be resolved during analysis stops the kernel listing any. A
                // standalone application still sees the commands registered through Artisan::starting().
            }
        }

        return $this->commands = $this->getApplication()->all();
    }

    /**
     * Get a standalone console application.
     */
    private function getApplication(): Application
    {
        if ($this->application === null) {
            $this->application = new Application(app(), app(Dispatcher::class), app()->version());
            $this->application->setContainerCommandLoader();
        }

        return $this->application;
    }
}
