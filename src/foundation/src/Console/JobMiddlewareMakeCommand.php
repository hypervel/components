<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:job-middleware')]
class JobMiddlewareMakeCommand extends GeneratorCommand
{
    use CreatesMatchingTest;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:job-middleware
                    {name : The name of the middleware}
                    {--f|force : Create the class even if the job middleware already exists}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new job middleware class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Middleware';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/job.middleware.stub');
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
        return $rootNamespace . '\Jobs\Middleware';
    }
}
