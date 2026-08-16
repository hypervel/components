<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Console\Commands;

use Closure;

class MakeResponse extends MakeCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'saloon:response';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new custom Saloon response class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Saloon response';

    /**
     * The namespace suffix beneath the integration.
     */
    protected string $namespace = '\{integration}\Responses';

    /**
     * The default stub.
     */
    protected string $stub = 'saloon.response.stub';

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, Closure|string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            ...parent::promptForMissingArgumentsUsing(),
            'name' => 'What should the Saloon response be named?',
        ];
    }
}
