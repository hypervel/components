<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:job')]
class JobMakeCommand extends GeneratorCommand
{
    use CreatesMatchingTest;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:job
                    {name : The name of the job}
                    {--f|force : Create the class even if the job already exists}
                    {--sync : Indicates that the job should be synchronous}
                    {--batched : Indicates that the job should be batchable}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new job class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Job';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        if ($this->option('batched')) {
            return $this->resolveStubPath('/stubs/job.batched.queued.stub');
        }

        return $this->option('sync')
            ? $this->resolveStubPath('/stubs/job.stub')
            : $this->resolveStubPath('/stubs/job.queued.stub');
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
        return $rootNamespace . '\Jobs';
    }
}
