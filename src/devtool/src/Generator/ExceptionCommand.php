<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class ExceptionCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:exception');
    }

    public function configure()
    {
        $this->setDescription('Create a new Exception class');

        parent::configure();
    }

    protected function getStub(): string
    {
        if ($this->input->getOption('render')) {
            $stub = $this->input->getOption('report')
                ? '/stubs/exception-render-report.stub'
                : '/stubs/exception-render.stub';
        } else {
            $stub = $this->input->getOption('report')
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
