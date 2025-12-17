<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;

class ComponentCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:component');
    }

    public function configure()
    {
        $this->setDescription('Create a new view component class');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/view-component.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\View\Components';
    }

    protected function buildClass(string $name): string
    {
        return $this->replaceView(parent::buildClass($name), $name);
    }

    protected function replaceView(string $stub, string $name): string
    {
        $view = lcfirst(str_replace($this->getNamespace($name) . '\\', '', $name));

        return str_replace(
            ['%VIEW%'],
            ["View::make('components.{$view}')"],
            $stub
        );
    }
}
