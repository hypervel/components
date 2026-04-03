<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\select;

#[AsCommand(name: 'make:enum')]
class EnumMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:enum';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new enum';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Enum';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        if ($this->option('string') || $this->option('int')) {
            return $this->resolveStubPath('/stubs/enum.backed.stub');
        }

        return $this->resolveStubPath('/stubs/enum.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->hypervel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . $stub;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return match (true) {
            is_dir(app_path('Enums')) => $rootNamespace . '\Enums',
            is_dir(app_path('Enumerations')) => $rootNamespace . '\Enumerations',
            default => $rootNamespace,
        };
    }

    /**
     * Build the class with the given name.
     *
     * @throws \Hypervel\Contracts\Filesystem\FileNotFoundException
     */
    protected function buildClass(string $name): string
    {
        if ($this->option('string') || $this->option('int')) {
            return str_replace(
                ['{{ type }}'],
                $this->option('string') ? 'string' : 'int',
                parent::buildClass($name)
            );
        }

        return parent::buildClass($name);
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        $type = select('Which type of enum would you like?', [
            'pure' => 'Pure enum',
            'string' => 'Backed enum (String)',
            'int' => 'Backed enum (Integer)',
        ]);

        if ($type !== 'pure') {
            $input->setOption($type, true);
        }
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['string', 's', InputOption::VALUE_NONE, 'Generate a string backed enum.'],
            ['int', 'i', InputOption::VALUE_NONE, 'Generate an integer backed enum.'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the enum even if the enum already exists'],
        ];
    }
}
