<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Hyperf\Stringable\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;

class ControllerCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:controller');
    }

    public function configure()
    {
        $this->setDescription('Create a new controller class');

        parent::configure();
    }

    protected function getStub(): string
    {
        $stub = null;

        if ($type = $this->input->getOption('type')) {
            $stub = "/stubs/controller.{$type}.stub";
        } elseif ($this->input->getOption('model')) {
            $stub = '/stubs/controller.model.stub';
        } elseif ($this->input->getOption('resource')) {
            $stub = '/stubs/controller.stub';
        }
        if ($this->input->getOption('api') && is_null($stub)) {
            $stub = '/stubs/controller.api.stub';
        } elseif ($this->input->getOption('api')) {
            $stub = str_replace('.stub', '.api.stub', $stub);
        }

        $stub ??= '/stubs/controller.plain.stub';

        return $this->getConfig()['stub'] ?? __DIR__ . $stub;
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Http\Controllers';
    }

    protected function getOptions(): array
    {
        return [
            ['namespace', 'N', InputOption::VALUE_OPTIONAL, 'The namespace for class.', null],
            ['api', null, InputOption::VALUE_NONE, 'Exclude the create and edit methods from the controller'],
            ['type', null, InputOption::VALUE_REQUIRED, 'Manually specify the controller stub file to use'],
            ['force', null, InputOption::VALUE_NONE, 'Create the class even if the controller already exists'],
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'Generate a resource controller for the given model'],
            ['resource', 'r', InputOption::VALUE_NONE, 'Generate a resource controller class'],
            ['requests', 'R', InputOption::VALUE_NONE, 'Generate FormRequest classes for store and update'],
        ];
    }

    protected function replaceClass(string $stub, string $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        if (! $model = $this->input->getOption('model')) {
            return $stub;
        }
        $modelNamespace = $this->qualifyModel($model);
        $model = class_basename($modelNamespace);
        $modelVariable = Str::camel($model);

        [$namespace, $storeRequest, $updateRequest] = [
            'Hypervel\Http', 'Request', 'Request',
        ];

        if ($this->input->getOption('requests')) {
            $namespace = 'App\Http\Requests';

            [$storeRequest, $updateRequest] = $this->generateFormRequests($model);
        }
        $namespacedRequests = $namespace . '\\' . $storeRequest . ';';

        if ($storeRequest !== $updateRequest) {
            $namespacedRequests .= PHP_EOL . 'use ' . $namespace . '\\' . $updateRequest . ';';
        }
        return str_replace(
            ['%NAMESPACED_MODEL%', '%MODEL%', '%MODEL_VARIABLE%', '%NAMESPACED_REQUESTS%', '%STORE_REQUEST%', '%UPDATE_REQUEST%'],
            [$modelNamespace, $model, $modelVariable, $namespacedRequests, $storeRequest, $updateRequest],
            $stub
        );
    }

    protected function generateFormRequests($modelClass): array
    {
        $storeRequestClass = 'Store' . $modelClass . 'Request';

        $this->call('make:request', [
            'name' => $storeRequestClass,
        ]);

        $updateRequestClass = 'Update' . $modelClass . 'Request';

        $this->call('make:request', [
            'name' => $updateRequestClass,
        ]);

        return [$storeRequestClass, $updateRequestClass];
    }

    protected function call(string $command, array $parameters = []): int
    {
        return $this->getApplication()->doRun(
            new ArrayInput(array_merge(['command' => $command], $parameters)),
            $this->output
        );
    }

    protected function qualifyModel(string $model)
    {
        $model = ltrim($model, '\/');

        $model = str_replace('/', '\\', $model);
        $modelNamespace = $this->getConfig()['model_namespace'] ?? 'App\Models';

        if (Str::startsWith($model, $modelNamespace)) {
            return $model;
        }

        return "{$modelNamespace}\\{$model}";
    }
}
