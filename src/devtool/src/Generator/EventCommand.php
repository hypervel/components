<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;

class EventCommand extends GeneratorCommand
{
    protected ?string $name = 'make:event';

    protected string $description = 'Create a new event class';

    protected string $type = 'Event';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/event.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Events';
    }
}
