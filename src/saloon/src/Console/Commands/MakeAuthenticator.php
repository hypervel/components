<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Console\Commands;

use Closure;

class MakeAuthenticator extends MakeCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'saloon:auth';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new Saloon authenticator';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Saloon authenticator';

    /**
     * The namespace suffix beneath the integration.
     */
    protected string $namespace = '\{integration}\Auth';

    /**
     * The default stub.
     */
    protected string $stub = 'saloon.authenticator.stub';

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, Closure|string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            ...parent::promptForMissingArgumentsUsing(),
            'name' => 'What should the Saloon authenticator be named?',
        ];
    }
}
