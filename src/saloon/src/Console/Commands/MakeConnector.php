<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Console\Commands;

use Closure;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MakeConnector extends MakeCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'saloon:connector';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new Saloon connector class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Saloon connector';

    /**
     * The namespace suffix beneath the integration.
     */
    protected string $namespace = '\{integration}';

    /**
     * Get the stub file name.
     */
    protected function resolveStubName(): string
    {
        return $this->option('oauth')
            ? 'saloon.oauth-connector.stub'
            : 'saloon.connector.stub';
    }

    /**
     * Get the command options.
     *
     * @return array<int, array<mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['oauth', null, InputOption::VALUE_NONE, 'Whether the connector should include the OAuth boilerplate'],
        ];
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        $supportOauth = $this->confirm('Should the connector support OAuth? (Authorization Code Grant)');

        $input->setOption('oauth', $supportOauth);
    }

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, Closure|string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            ...parent::promptForMissingArgumentsUsing(),
            'name' => 'What should the Saloon connector be named?',
        ];
    }
}
