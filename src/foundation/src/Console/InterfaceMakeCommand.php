<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:interface')]
class InterfaceMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:interface';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new interface';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Interface';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return __DIR__ . '/stubs/interface.stub';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return match (true) {
            is_dir(app_path('Contracts')) => $rootNamespace . '\Contracts',
            is_dir(app_path('Interfaces')) => $rootNamespace . '\Interfaces',
            default => $rootNamespace,
        };
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the interface even if the interface already exists'],
        ];
    }
}
