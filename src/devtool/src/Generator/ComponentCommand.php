<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:component')]
class ComponentCommand extends DevtoolGeneratorCommand
{
    protected ?string $name = 'make:component';

    protected string $description = 'Create a new view component class';

    protected string $type = 'Component';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/view-component.stub';
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $this->getConfig()['namespace'] ?? 'App\View\Components';
    }

    protected function buildClass(string $name): string
    {
        return $this->replaceView(parent::buildClass($name), $name);
    }

    protected function replaceView(string $stub, string $name): string
    {
        $view = str_replace($this->getDefaultNamespace($this->rootNamespace()) . '\\', '', $name);
        $view = array_map(
            fn ($part) => Str::snake($part),
            explode('\\', $view)
        );
        $view = implode('.', $view);

        return str_replace(
            ['%VIEW%'],
            ["View::make('components.{$view}')"],
            $stub
        );
    }
}
