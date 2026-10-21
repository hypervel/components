<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:class')]
class ClassMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:class
                    {name : The name of the class}
                    {--i|invokable : Generate a single method, invokable class}
                    {--f|force : Create the class even if the class already exists}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Class';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->option('invokable')
            ? $this->resolveStubPath('/stubs/class.invokable.stub')
            : $this->resolveStubPath('/stubs/class.stub');
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
}
