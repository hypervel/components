<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;

class MiddlewareCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:middleware');
    }

    public function configure()
    {
        $this->setDescription('Create a new HTTP middleware class');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/middleware.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Http\Middleware';
    }
}
