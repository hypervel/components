<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\confirm;

#[AsCommand(name: 'make:exception')]
class ExceptionMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:exception';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new custom exception class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Exception';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        if ($this->option('render')) {
            return $this->option('report')
                ? __DIR__ . '/stubs/exception-render-report.stub'
                : __DIR__ . '/stubs/exception-render.stub';
        }

        return $this->option('report')
            ? __DIR__ . '/stubs/exception-report.stub'
            : __DIR__ . '/stubs/exception.stub';
    }

    /**
     * Determine if the class already exists.
     */
    protected function alreadyExists(string $rawName): bool
    {
        return class_exists($this->rootNamespace() . 'Exceptions\\' . $rawName);
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Exceptions';
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        $input->setOption('report', confirm('Should the exception have a report method?', default: false));
        $input->setOption('render', confirm('Should the exception have a render method?', default: false));
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the exception already exists'],
            ['render', null, InputOption::VALUE_NONE, 'Create the exception with an empty render method'],
            ['report', null, InputOption::VALUE_NONE, 'Create the exception with an empty report method'],
        ];
    }
}
