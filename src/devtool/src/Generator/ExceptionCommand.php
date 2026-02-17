<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class ExceptionCommand extends GeneratorCommand
{
    protected ?string $name = 'make:exception';

    protected string $description = 'Create a new Exception class';

    protected string $type = 'Exception';

    protected function getStub(): string
    {
        if ($this->option('render')) {
            $stub = $this->option('report')
                ? '/stubs/exception-render-report.stub'
                : '/stubs/exception-render.stub';
        } else {
            $stub = $this->option('report')
                ? '/stubs/exception-report.stub'
                : '/stubs/exception.stub';
        }
        return $this->getConfig()['stub'] ?? __DIR__ . $stub;
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Exceptions';
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['render', null, InputOption::VALUE_NONE, 'Create the exception with an empty render method'],
            ['report', null, InputOption::VALUE_NONE, 'Create the exception with an empty report method'],
        ]);
    }
}
