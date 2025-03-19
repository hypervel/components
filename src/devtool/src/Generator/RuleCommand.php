<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hyperf\Devtool\Generator\GeneratorCommand;

class RuleCommand extends GeneratorCommand
{
    public function __construct()
    {
        parent::__construct('make:rule');
    }

    public function configure()
    {
        $this->setDescription('Create a new validation rule');

        parent::configure();
    }

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/rule.stub';
    }

    protected function getDefaultNamespace(): string
    {
        return $this->getConfig()['namespace'] ?? 'App\Rules';
    }
}
