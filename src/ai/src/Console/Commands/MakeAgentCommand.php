<?php

declare(strict_types=1);

namespace Hypervel\Ai\Console\Commands;

use Hypervel\Console\Attributes\Description;
use Hypervel\Console\GeneratorCommand;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\confirm;

#[AsCommand(name: 'make:agent')]
#[Description('Create a new agent')]
class MakeAgentCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:agent';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Agent';

    /**
     * Get the default namespace for the class.
     */
    #[Override]
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Ai\Agents';
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        if ($this->option('structured')) {
            return $this->resolveStubPath('/stubs/structured-agent.stub');
        }

        return $this->resolveStubPath('/stubs/agent.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . '/../../../' . $stub;
    }

    /**
     * Get the console command arguments.
     */
    #[Override]
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the agent even if the agent already exists'],
            ['structured', 's', InputOption::VALUE_NONE, 'Generate an agent that returns structured output'],
        ];
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     */
    #[Override]
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        $input->setOption('structured', confirm(
            label: 'Will your agent generate structured output?',
            default: false,
        ));
    }
}
