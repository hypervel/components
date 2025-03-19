<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;

class ListenerCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:listener');
    }

    public function configure()
    {
        $this->setDescription('Create a new event listener class');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/listener.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Listeners';
    }
}
