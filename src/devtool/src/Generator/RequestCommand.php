<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;

class RequestCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:request');
    }

    public function configure()
    {
        $this->setDescription('Create a new form request class');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/request.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Http\Requests';
    }
}
