<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:scope')]
class ScopeMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:scope
                    {name : The name of the scope}
                    {--f|force : Create the class even if the scope already exists}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new scope class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Scope';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/scope.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . $stub;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return is_dir(app_path('Models')) ? $rootNamespace . '\Models\Scopes' : $rootNamespace . '\Scopes';
    }
}
