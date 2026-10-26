<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\confirm;

#[AsCommand(name: 'make:diagnostic')]
class DiagnosticMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:diagnostic';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new Doctor diagnostic class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Diagnostic';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->input()->boolean('fixable')
            ? $this->resolveStubPath('/stubs/diagnostic.fixable.stub')
            : $this->resolveStubPath('/stubs/diagnostic.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        $customPath = $this->hypervel->basePath(trim($stub, '/'));

        return file_exists($customPath) ? $customPath : __DIR__ . $stub;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Doctor\Diagnostics';
    }

    /**
     * Interact with the user, asking whether the diagnostic should offer a fix.
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        if (! $input->getOption('fixable')) {
            $input->setOption('fixable', confirm('Should the diagnostic offer a fix?', default: false));
        }
    }

    /**
     * Get the console command options.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['fixable', null, InputOption::VALUE_NONE, 'Generate a diagnostic that can fix the failure it detects'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the diagnostic already exists'],
        ];
    }
}
