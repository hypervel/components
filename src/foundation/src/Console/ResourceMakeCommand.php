<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:resource')]
class ResourceMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:resource';

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

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the resource already exists'],
            ['json-api', 'j', InputOption::VALUE_NONE, 'Create a JSON:API resource'],
            ['collection', 'c', InputOption::VALUE_NONE, 'Create a resource collection'],
        ];
    }
}
