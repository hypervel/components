<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:cast')]
class CastMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:cast
                    {name : The name of the cast}
                    {--f|force : Create the class even if the cast already exists}
                    {--inbound : Generate an inbound cast class}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new custom Eloquent cast class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Cast';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->option('inbound')
            ? $this->resolveStubPath('/stubs/cast.inbound.stub')
            : $this->resolveStubPath('/stubs/cast.stub');
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
        return $rootNamespace . '\Casts';
    }
}
