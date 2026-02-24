<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:resource')]
class ResourceCommand extends GeneratorCommand
{
    protected ?string $name = 'make:resource';

    protected string $description = 'Create a new resource';

    protected string $type = 'Resource';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . (
            str_ends_with($this->argument('name'), 'Collection')
            || $this->option('collection')
            ? '/stubs/resource-collection.stub'
            : '/stubs/resource.stub'
        );
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Http\Resources';
    }

    protected function getOptions(): array
    {
        return [
            ['namespace', 'N', InputOption::VALUE_OPTIONAL, 'The namespace for class.', null],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the resource already exists'],
            ['collection', 'c', InputOption::VALUE_NONE, 'Create a resource collection'],
        ];
    }
}
