<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;

class EventCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:event');
    }

    public function configure()
    {
        $this->setDescription('Create a new event class');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/event.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Events';
    }
}
