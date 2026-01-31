<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class TestCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:test');
    }

    public function configure()
    {
        $this->setDescription('Create a new test class');

        parent::configure();
    }

    protected function getStub(): string
    {
        $stub = $this->input->getOption('unit')
            ? 'test.unit.stub'
            : 'test.stub';

        return $this->getConfig()['stub'] ?? __DIR__ . "/stubs/{$stub}";
    }

    protected function getDefaultNamespace(): string
    {
        $namespace = $this->input->getOption('unit')
            ? 'Tests\Unit'
            : 'Tests\Feature';

        return $this->getConfig()['namespace'] ?? $namespace;
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['unit', 'u', InputOption::VALUE_NONE, 'Whether create a unit test.'],
        ]);
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $namespace = $this->input->getOption('namespace');
        if (empty($namespace)) {
            $namespace = $this->getDefaultNamespace();
        }

        $filename = str_replace($namespace . '\\', '', "{$name}.php");
        $filename = str_replace('\\', '/', $filename);

        $path = $this->input->getOption('path')
            ?: ($this->input->getOption('unit') ? 'tests/Unit' : 'tests/Feature');

        return "{$path}/{$filename}";
    }
}
