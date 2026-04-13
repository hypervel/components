<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Hypervel\Foundation\Inspiring;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:component')]
class ComponentMakeCommand extends GeneratorCommand
{
    use CreatesMatchingTest;

    /**
     * The console command name.
     */
    protected ?string $name = 'make:component';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new view component class';

    /**
     * The type of class being generated.
     */
    protected string $type = 'Component';

    /**
     * Execute the console command.
     */
    public function handle(): bool|int
    {
        if ($this->option('view')) {
            $this->writeView();

            return static::SUCCESS;
        }

        if (parent::handle() === false && ! $this->option('force')) {
            return static::SUCCESS;
        }

        if (! $this->option('inline')) {
            $this->writeView();
        }

        return static::SUCCESS;
    }

    /**
     * Write the view for the component.
     */
    protected function writeView(): void
    {
        $separator = '/';

        if (windows_os()) {
            $separator = '\\';
        }

        $path = $this->viewPath(
            str_replace('.', $separator, $this->getView()) . '.blade.php'
        );

        if (! $this->files->isDirectory(dirname($path))) {
            $this->files->makeDirectory(dirname($path), 0777, true, true);
        }

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error('View already exists.');

            return;
        }

        file_put_contents(
            $path,
            '<div>
    <!-- ' . Inspiring::quotes()->random() . ' -->
</div>'
        );

        $this->components->info(sprintf('%s [%s] created successfully.', 'View', $path));
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        if ($this->option('inline')) {
            return str_replace(
                ['DummyView', '{{ view }}'],
                "<<<'blade'\n<div>\n    <!-- " . Inspiring::quotes()->random() . " -->\n</div>\nblade",
                parent::buildClass($name)
            );
        }

        return str_replace(
            ['DummyView', '{{ view }}'],
            'view(\'' . $this->getView() . '\')',
            parent::buildClass($name)
        );
    }

    /**
     * Get the view name relative to the view path.
     */
    protected function getView(): string
    {
        $segments = explode('/', str_replace('\\', '/', $this->argument('name')));

        $name = array_pop($segments);

        $path = is_string($this->option('path'))
            ? explode('/', trim($this->option('path'), '/'))
            : [
                'components',
                ...$segments,
            ];

        $path[] = $name;

        return (new Collection($path))
            ->map(fn ($segment) => Str::kebab($segment))
            ->implode('.');
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/view-component.stub');
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
        return $rootNamespace . '\View\Components';
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['inline', null, InputOption::VALUE_NONE, 'Create a component that renders an inline view'],
            ['view', null, InputOption::VALUE_NONE, 'Create an anonymous component with only a view'],
            ['path', null, InputOption::VALUE_REQUIRED, 'The location where the component view should be created'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the component already exists'],
        ];
    }
}
