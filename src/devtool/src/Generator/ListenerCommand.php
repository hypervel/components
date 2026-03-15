<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:listener')]
class ListenerCommand extends DevtoolGeneratorCommand
{
    protected ?string $name = 'make:listener';

    protected string $description = 'Create a new event listener class';

    protected string $type = 'Listener';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/listener.stub';
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Listeners';
    }
}
