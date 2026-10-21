<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:trait')]
class TraitMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:trait
                    {name : The name of the trait}
                    {--f|force : Create the trait even if the trait already exists}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new trait';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Trait';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/trait.stub');
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
        return match (true) {
            is_dir(app_path('Concerns')) => $rootNamespace . '\Concerns',
            is_dir(app_path('Traits')) => $rootNamespace . '\Traits',
            default => $rootNamespace,
        };
    }
}
