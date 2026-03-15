<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\GeneratorCommand;
use Hypervel\Foundation\Inspiring;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:view')]
class ViewMakeCommand extends GeneratorCommand
{
    use CreatesMatchingTest;

    /**
     * The console command name.
     */
    protected ?string $name = 'make:view';

    /**
     * The console command description.
     */
    protected string $description = 'Create a new view';

    /**
     * The type of file being generated.
     */
    protected string $type = 'View';

    /**
     * Build the class with the given name.
     *
     * @throws \Hypervel\Contracts\Filesystem\FileNotFoundException
     */
    protected function buildClass(string $name): string
    {
        $contents = parent::buildClass($name);

        return str_replace(
            '{{ quote }}',
            Inspiring::quotes()->random(),
            $contents,
        );
    }

    /**
     * Get the destination view path.
     */
    protected function getPath(string $name): string
    {
        return $this->viewPath(
            $this->getNameInput() . '.' . $this->option('extension'),
        );
    }

    /**
     * Get the desired view name from the input.
     */
    protected function getNameInput(): string
    {
        $name = trim($this->argument('name'));

        return str_replace(['\\', '.'], '/', $name);
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath(
            '/stubs/view.stub',
        );
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
     * Get the destination test case path.
     */
    protected function getTestPath(): string
    {
        return base_path(
            Str::of($this->testClassFullyQualifiedName())
                ->replace('\\', '/')
                ->replaceFirst('Tests/Feature', 'tests/Feature')
                ->append('Test.php')
                ->value()
        );
    }

    /**
     * Create the matching test case if requested.
     */
    protected function handleTestCreation(string $path): bool
    {
        if (! $this->option('test') && ! $this->option('pest') && ! $this->option('phpunit')) {
            return false;
        }

        $contents = preg_replace(
            ['/\{{ namespace \}}/', '/\{{ class \}}/', '/\{{ name \}}/'],
            [$this->testNamespace(), $this->testClassName(), $this->testViewName()],
            File::get($this->getTestStub()),
        );

        File::ensureDirectoryExists(dirname($this->getTestPath()), 0755, true);

        $result = File::put($path = $this->getTestPath(), $contents);

        $this->components->info(sprintf('%s [%s] created successfully.', 'Test', $path));

        return $result !== false;
    }

    /**
     * Get the namespace for the test.
     */
    protected function testNamespace(): string
    {
        return Str::of($this->testClassFullyQualifiedName())
            ->beforeLast('\\')
            ->value();
    }

    /**
     * Get the class name for the test.
     */
    protected function testClassName(): string
    {
        return Str::of($this->testClassFullyQualifiedName())
            ->afterLast('\\')
            ->append('Test')
            ->value();
    }

    /**
     * Get the class fully-qualified name for the test.
     */
    protected function testClassFullyQualifiedName(): string
    {
        $name = Str::of(Str::lower($this->getNameInput()))->replace('.' . $this->option('extension'), '');

        $namespacedName = Str::of(
            (new Stringable($name))
                ->replace('/', ' ')
                ->explode(' ')
                ->map(fn ($part) => (new Stringable($part))->ucfirst())
                ->implode('\\')
        )
            ->replace(['-', '_'], ' ')
            ->explode(' ')
            ->map(fn ($part) => (new Stringable($part))->ucfirst())
            ->implode('');

        return 'Tests\Feature\View\\' . $namespacedName;
    }

    /**
     * Get the test stub file for the generator.
     */
    protected function getTestStub(): string
    {
        $stubName = 'view.' . ($this->usingPest() ? 'pest' : 'test') . '.stub';

        return file_exists($customPath = $this->hypervel->basePath("stubs/{$stubName}"))
            ? $customPath
            : __DIR__ . '/stubs/' . $stubName;
    }

    /**
     * Get the view name for the test.
     */
    protected function testViewName(): string
    {
        return Str::of($this->getNameInput())
            ->replace('/', '.')
            ->lower()
            ->value();
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

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['extension', null, InputOption::VALUE_OPTIONAL, 'The extension of the generated view', 'blade.php'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the view even if the view already exists'],
        ];
    }
}
