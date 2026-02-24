<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:rule')]
class RuleCommand extends GeneratorCommand
{
    protected ?string $name = 'make:rule';

    protected string $description = 'Create a new validation rule';

    protected string $type = 'Rule';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/rule.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Rules';
    }
}
