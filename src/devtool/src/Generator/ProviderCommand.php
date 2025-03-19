<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;

class ProviderCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:provider');
    }

    public function configure()
    {
        $this->setDescription('Create a new service provider class');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/provider.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Providers';
    }
}
