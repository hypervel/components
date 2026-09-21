<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:request')]
class RequestMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:request
                    {name : The name of the request}
                    {--f|force : Create the class even if the request already exists}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new form request class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Request';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/request.stub');
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
        return $rootNamespace . '\Http\Requests';
    }
}
