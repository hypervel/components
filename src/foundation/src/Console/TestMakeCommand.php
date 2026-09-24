<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Hypervel\Prompts\select;

#[AsCommand(name: 'make:test')]
class TestMakeCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:test
                    {name : The name of the test}
                    {--f|force : Create the test even if the test already exists}
                    {--u|unit : Create a unit test}
                    {--pest : Create a Pest test}
                    {--phpunit : Create a PHPUnit test}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new test class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Test';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        $suffix = $this->option('unit') ? '.unit.stub' : '.stub';

        return $this->usingPest()
            ? $this->resolveStubPath('/stubs/pest' . $suffix)
            : $this->resolveStubPath('/stubs/test' . $suffix);
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
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        $name = Str::replaceFirst($this->rootNamespace(), '', $name);

        return base_path('tests') . str_replace('\\', '/', $name) . '.php';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        if ($this->option('unit')) {
            return $rootNamespace . '\Unit';
        }

        return $rootNamespace . '\Feature';
    }

    /**
     * Get the root namespace for the class.
     */
    protected function rootNamespace(): string
    {
        return 'Tests';
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->isReservedName($this->getNameInput()) || $this->didReceiveOptions($input)) {
            return;
        }

        $type = select('Which type of test would you like?', [
            'feature' => 'Feature',
            'unit' => 'Unit',
        ]);

        if ($type === 'unit') {
            $input->setOption('unit', true);
        }
    }

    /**
     * Determine if Pest is being used by the application.
     */
    protected function usingPest(): bool
    {
        if ($this->option('phpunit')) {
            return false;
        }

        return $this->option('pest')
            || (function_exists('\Pest\version')
                && file_exists(base_path('tests') . '/Pest.php'));
    }
}
