<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

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
        return $this->getConfig()['stub'] ?? __DIR__ . (
            $this->input->getOption('psr15')
            ? '/stubs/middleware.psr15.stub'
            : '/stubs/middleware.stub'
        );
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Http\Middleware';
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['psr15', null, InputOption::VALUE_NONE, 'Create a PSR-15 compatible middleware'],
        ]);
    }
}
