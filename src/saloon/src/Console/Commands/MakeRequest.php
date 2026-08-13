<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Console\Commands;

use Closure;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Saloon\Enums\Method;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\select;

class MakeRequest extends MakeCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'saloon:request';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new Saloon request class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Saloon request';

    /**
     * The namespace suffix beneath the integration.
     */
    protected string $namespace = '\{integration}\Requests';

    /**
     * The default stub.
     */
    protected string $stub = 'saloon.request.stub';

    /**
     * Get the command options.
     *
     * @return array<int, array<mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['method', 'm', InputOption::VALUE_REQUIRED, 'The method of the request'],
        ];
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
            'name' => 'What should the Saloon request be named?',
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

        $methodType = select(
            'What method should the Saloon request send?',
            array_map(fn (Method $method): string => $method->name, Method::cases())
        );

        $input->setOption('method', $methodType);
    }

    /**
     * Build the class with the given name.
     *
     * @throws FileNotFoundException
     */
    protected function buildClass(string $name): string
    {
        $method = $this->option('method') ?? 'GET';

        if (! is_string($method)) {
            throw new InvalidArgumentException('The method option must be a string.');
        }

        $method = Method::tryFrom(strtoupper($method))
            ?? throw new InvalidArgumentException("The method [{$method}] is not supported.");

        $stub = $this->files->get($this->getStub());
        $stub = $this->replaceMethod($stub, $method->name);

        return $this->replaceNamespace($stub, $name)->replaceClass($stub, $name);
    }

    /**
     * Replace the method for the stub.
     */
    protected function replaceMethod(string $stub, string $name): string
    {
        return str_replace('{{ method }}', $name, $stub);
    }
}
