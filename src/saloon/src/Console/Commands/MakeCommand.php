<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Console\Commands;

use Closure;
use Hypervel\Console\GeneratorCommand;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use LogicException;
use Symfony\Component\Console\Input\InputArgument;

use function Hypervel\Prompts\suggest;

abstract class MakeCommand extends GeneratorCommand
{
    /**
     * The default stub.
     */
    protected string $stub = '';

    /**
     * The namespace suffix beneath the integration.
     */
    protected string $namespace = '';

    /**
     * Create a new command instance.
     */
    public function __construct(
        Filesystem $files,
        protected Repository $config,
    ) {
        parent::__construct($files);
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return $this->resolveStubPath($this->resolveStubName());
    }

    /**
     * Get the stub file name.
     */
    protected function resolveStubName(): string
    {
        return $this->stub;
    }

    /**
     * Resolve the fully qualified path to the stub.
     */
    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->hypervel->basePath('stubs/' . $stub))
            ? $customPath
            : dirname(__DIR__, 3) . '/stubs/' . $stub;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        $namespace = $this->integrationNamespace();

        return str_replace(
            '{integration}',
            $this->getIntegration(),
            $namespace . $this->namespace,
        );
    }

    /**
     * Qualify the class against the independently configured namespace.
     */
    protected function qualifyClass(string $name): string
    {
        $name = str_replace('/', '\\', ltrim($name, '\/'));
        $targetNamespace = $this->option('target-namespace');

        if (is_string($targetNamespace) && $targetNamespace !== '') {
            return trim($targetNamespace, '\\') . '\\' . $name;
        }

        $namespace = $this->getDefaultNamespace($this->rootNamespace());

        return Str::startsWith($name, $namespace . '\\')
            ? $name
            : $namespace . '\\' . $name;
    }

    /**
     * Get the configured integrations namespace.
     */
    protected function integrationNamespace(): string
    {
        $namespace = $this->config->get('saloon.integrations_namespace');

        if ($namespace === null) {
            return rtrim($this->rootNamespace(), '\\') . '\Http\Integrations';
        }

        if (! is_string($namespace) || $namespace === '') {
            throw new LogicException('The [saloon.integrations_namespace] configuration value must be null or a non-empty string.');
        }

        return trim($namespace, '\\');
    }

    /**
     * Get the selected integration name.
     */
    protected function getIntegration(): string
    {
        $integration = $this->argument('integration');

        if (! is_string($integration)) {
            throw new LogicException('The integration argument must be a string.');
        }

        return $integration;
    }

    /**
     * Get the console command arguments.
     *
     * @return array<mixed, mixed>
     */
    protected function getArguments(): array
    {
        return [
            ['integration', InputArgument::REQUIRED, 'The related integration'],
            ...parent::getArguments(),
        ];
    }

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, array{string, string}|Closure(): (array<int, string>|bool|int|string)|string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'integration' => fn () => suggest(
                label: 'What is the related integration?',
                options: fn (string $value) => $this->getExistingIntegrations($value),
                required: true,
                hint: 'Start typing to search or enter a new integration name'
            ),
        ];
    }

    /**
     * Get existing integrations filtered by the search value.
     *
     * @return array<int, string>
     */
    protected function getExistingIntegrations(string $search = ''): array
    {
        $integrationsPath = $this->config->string('saloon.integrations_path');

        if (! $this->files->isDirectory($integrationsPath)) {
            return [];
        }

        $directories = $this->files->directories($integrationsPath);
        $integrations = array_map(fn (string $path): string => basename($path), $directories);

        if (mb_strlen($search) === 0) {
            return $integrations;
        }

        return array_values(array_filter(
            $integrations,
            fn (string $integration): bool => str_contains(mb_strtolower($integration), mb_strtolower($search))
        ));
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        if ($this->option('target-path')) {
            return parent::getPath($name);
        }

        $targetNamespace = $this->option('target-namespace');

        if (is_string($targetNamespace) && $targetNamespace !== '') {
            $namespaceSuffix = str_replace('{integration}', $this->getIntegration(), $this->namespace);
            $relativeName = trim($namespaceSuffix, '\\') . '\\' . $this->getNameInput();
        } else {
            $relativeName = Str::after($name, $this->integrationNamespace() . '\\');
        }

        return rtrim($this->config->string('saloon.integrations_path'), '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeName)
            . '.php';
    }
}
