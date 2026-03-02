<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:seeder')]
class SeederCommand extends DevtoolGeneratorCommand
{
    protected ?string $name = 'make:seeder';

    protected string $description = 'Create a new seeder class';

    protected string $type = 'Seeder';

    protected function getStub(): string
    {
        return $this->getConfig()['stub'] ?? __DIR__ . '/stubs/seeder.stub';
    }

    /**
     * Parse the class name and format according to the root namespace.
     */
    protected function qualifyClass(string $name): string
    {
        return $name;
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $path = $this->getConfig()['path'] ?? 'database/seeders';

        return BASE_PATH . "/{$path}/{$name}.php";
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return '';
    }
}
