<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Console\Commands;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'make:mcp-app-resource',
    description: 'Create a new MCP app resource class and linked view'
)]
class MakeAppResourceCommand extends GeneratorCommand
{
    protected string $type = 'AppResource';

    /**
     * Generate the app resource and its view.
     */
    public function handle(): bool|int
    {
        $result = parent::handle();

        if ($result === false) {
            return false;
        }

        $this->createBladeView();

        return $result;
    }

    /**
     * Build the app resource class with its view name.
     */
    protected function buildClass(string $name): string
    {
        $viewName = collect(explode('/', $this->getKebabName()))
            ->implode('.');

        return str_replace(
            '{{ view }}',
            'mcp.' . $viewName,
            parent::buildClass($name),
        );
    }

    /**
     * Get the resource class stub.
     */
    protected function getStub(): string
    {
        return $this->resolveStub('mcp-app-resource.stub');
    }

    /**
     * Get the default resource namespace.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return "{$rootNamespace}\\Mcp\\Resources";
    }

    /**
     * Create the linked Blade view.
     */
    protected function createBladeView(): void
    {
        $viewPath = $this->getViewPath();

        if ($this->files->exists($viewPath) && ! $this->option('force')) {
            $this->components->warn("View [{$viewPath}] already exists.");

            return;
        }

        $this->files->ensureDirectoryExists(dirname($viewPath));

        $this->files->put($viewPath, $this->files->get($this->getViewStub()));

        $this->components->info("View [{$viewPath}] created successfully.");
    }

    /**
     * Get the view stub.
     */
    protected function getViewStub(): string
    {
        return $this->resolveStub('mcp-app-resource.view.stub');
    }

    /**
     * Resolve a published or package stub.
     */
    protected function resolveStub(string $name): string
    {
        return file_exists($customPath = $this->getHypervel()->basePath("stubs/{$name}"))
            ? $customPath
            : __DIR__ . "/../../../stubs/{$name}";
    }

    /**
     * Get the linked view path.
     */
    protected function getViewPath(): string
    {
        return resource_path('views/mcp/' . $this->getKebabName() . '.blade.php');
    }

    /**
     * Convert the resource name into view path segments.
     */
    protected function getKebabName(): string
    {
        return collect(explode('/', $this->getNameInput()))
            ->map(fn (string $segment) => Str::kebab($segment))
            ->implode('/');
    }

    /**
     * Get the generation options.
     *
     * @return array<int, array<int, int|string>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the resource already exists'],
        ];
    }
}
