<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;

class FactoryCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:factory');
    }

    public function configure()
    {
        $this->setDescription('Create a new model factory');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/factory.stub';
    }

    protected function getModelNamespace(string $name): string
    {
        $namespace = $this->getConfig()['model_namespace'] ?? 'App\Models';

        return "{$namespace}\\{{$name}}";
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $replace = [
            '%CLASS%' => $name,
            '%MODEL_NAMESPACE%' => $this->getModelNamespace($name),
        ];

        return str_replace(
            array_keys($replace),
            array_values($replace),
            $stub
        );
    }

    /**
     * Parse the class name and format according to the root namespace.
     */
    protected function qualifyClass(string $name): string
    {
        return $name;
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $path = $this->getConfig()['path'] ?? 'database/factories';

        return BASE_PATH . "/{$path}/{$name}.php";
    }

    protected function getDefaultNamespace(): string
    {
        return '';
    }
}
