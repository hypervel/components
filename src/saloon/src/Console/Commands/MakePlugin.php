<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Console\Commands;

use Closure;

class MakePlugin extends MakeCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'saloon:plugin';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new Saloon plugin';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Saloon plugin';

    /**
     * The namespace suffix beneath the integration.
     */
    protected string $namespace = '\{integration}\Plugins';

    /**
     * The default stub.
     */
    protected string $stub = 'saloon.plugin.stub';

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, Closure|string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            ...parent::promptForMissingArgumentsUsing(),
            'name' => 'What should the Saloon plugin be named?',
        ];
    }
}
