<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:interface')]
class InterfaceMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:interface
                    {name : The name of the interface}
                    {--f|force : Create the interface even if the interface already exists}';

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
}
