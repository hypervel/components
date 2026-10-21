<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:resource')]
class ResourceMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:resource
                    {name : The name of the resource}
                    {--f|force : Create the class even if the resource already exists}
                    {--j|json-api : Create a JSON:API resource}
                    {--c|collection : Create a resource collection}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new resource';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Resource';

    /**
     * Execute the console command.
     */
    public function handle(): bool|int
    {
        if ($this->collection()) {
            $this->type = 'Resource collection';
        }

        return parent::handle();
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return match (true) {
            $this->collection() => $this->resolveStubPath('/stubs/resource-collection.stub'),
            $this->option('json-api') => $this->resolveStubPath('/stubs/resource-json-api.stub'),
            default => $this->resolveStubPath('/stubs/resource.stub'),
        };
    }

    /**
     * Determine if the command is generating a resource collection.
     */
    protected function collection(): bool
    {
        return $this->option('collection')
            || str_ends_with($this->argument('name'), 'Collection');
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
        return $rootNamespace . '\Http\Resources';
    }
}
