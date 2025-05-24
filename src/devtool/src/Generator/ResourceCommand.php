<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class ResourceCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:resource');
    }

    public function configure()
    {
        $this->setDescription('Create a new resource');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . (
            str_ends_with($this->input->getArgument('name'), 'Collection')
            || $this->input->getOption('collection')
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
