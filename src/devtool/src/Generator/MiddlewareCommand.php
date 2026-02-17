<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class MiddlewareCommand extends GeneratorCommand
{
    protected ?string $name = 'make:middleware';

    protected string $description = 'Create a new HTTP middleware class';

    protected string $type = 'Middleware';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . (
            $this->option('psr15')
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
