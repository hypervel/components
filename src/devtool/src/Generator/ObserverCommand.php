<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Hyperf\Stringable\Str;
use Symfony\Component\Console\Input\InputOption;

class ObserverCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:observer');
    }

    public function configure()
    {
        $this->setDescription('Create a new model observer class');

        parent::configure();
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        if (! $model = trim($this->input->getOption('model') ?? '')) {
            $modelParts = explode('\\', $name);
            $model = end($modelParts);
            $model = Str::ucfirst(Str::before($model, 'Observer')) ?? 'Dummy';
        }

        $modelNamespace = $this->getConfig()['model_namespace'] ?? 'App\Models';
        $modelNamespace = "{$modelNamespace}\\{$model}";
        $modelVariable = Str::camel($model);

        return str_replace(
            ['%NAMESPACE_MODEL%', '%MODEL%', '%MODEL_VARIABLE%'],
            [$modelNamespace, $model, $modelVariable],
            $stub
        );
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/observer.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Observers';
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The model that the observer applies to'],
        ]);
    }
}
