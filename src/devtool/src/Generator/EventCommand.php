<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:event')]
class EventCommand extends DevtoolGeneratorCommand
{
    protected ?string $name = 'make:event';

    protected string $description = 'Create a new event class';

    protected string $type = 'Event';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/event.stub';
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Events';
    }
}
