<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:test')]
class TestCommand extends DevtoolGeneratorCommand
{
    protected ?string $name = 'make:test';

    protected string $description = 'Create a new test class';

    protected string $type = 'Test';

    protected function getStub(): string
    {
        $stub = $this->option('unit')
            ? 'test.unit.stub'
            : 'test.stub';

        return $this->getConfig()['stub'] ?? __DIR__ . "/stubs/{$stub}";
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        $namespace = $this->option('unit')
            ? 'Tests\Unit'
            : 'Tests\Feature';

        return $this->getConfig()['namespace'] ?? $namespace;
    }

    protected function getOptions(): array
    {
        $options = array_filter(parent::getOptions(), function ($item) {
            return $item[0] !== 'path';
        });

        return array_merge(array_values($options), [
            ['unit', 'u', InputOption::VALUE_NONE, 'Whether create a unit test.'],
            ['path', 'p', InputOption::VALUE_OPTIONAL, 'The path of the test class.'],
        ]);
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $namespace = $this->option('namespace');
        if (empty($namespace)) {
            $namespace = $this->getDefaultNamespace($this->rootNamespace());
        }

        $filename = str_replace($namespace . '\\', '', "{$name}.php");
        $filename = str_replace('\\', '/', $filename);

        $path = $this->option('path')
            ?: ($this->option('unit') ? 'tests/Unit' : 'tests/Feature');

        return "{$path}/{$filename}";
    }
}
