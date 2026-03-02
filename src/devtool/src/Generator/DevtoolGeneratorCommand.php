<?php

declare(strict_types=1);

namespace Hypervel\Devtool\Generator;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Base class for devtool generator commands.
 *
 * Extends the Laravel-style GeneratorCommand with devtool-specific features:
 * configurable namespaces/paths via options, IDE integration, and Hyperf-style
 * stub placeholders (%NAMESPACE%, %CLASS%).
 */
abstract class DevtoolGeneratorCommand extends GeneratorCommand
{
    /**
     * Execute the console command.
     */
    public function handle(): bool|int
    {
        // First we need to ensure that the given name is not a reserved word within the PHP
        // language and that the class name will actually be valid. If it is not valid we
        // can error now and prevent from polluting the filesystem using invalid files.
        if ($this->isReservedName($this->getNameInput())) {
            $this->components->error('The name "' . $this->getNameInput() . '" is reserved by PHP.');
            return self::FAILURE;
        }

        $name = $this->qualifyClass($this->getNameInput());
        $path = $this->getPath($name);

        // Next, We will check to see if the class already exists. If it does, we don't want
        // to create the class and overwrite the user's code. So, we will bail out so the
        // code is untouched. Otherwise, we will continue generating this class' files.
        if ((! $this->hasOption('force') || ! $this->option('force'))
            && $this->alreadyExists($this->getNameInput())) {
            $this->components->error(($this->type ?: $name) . ' already exists.');
            return self::FAILURE;
        }

        // Next, we will generate the path to the location where this class' file should get
        // written. Then, we will build the class and make the proper replacements on the
        // stub files so that it gets the correctly formatted namespace and class name.
        $this->makeDirectory($path);

        file_put_contents($path, $this->sortImports($this->buildClass($name)));

        $info = $this->type ?: $name;

        $this->components->info(sprintf('%s [%s] created successfully.', $info, $path));

        $this->openWithIde($path);

        return self::SUCCESS;
    }

    /**
     * Parse the class name and format according to the root namespace.
     *
     * Supports the --namespace option to override the default namespace.
     */
    protected function qualifyClass(string $name): string
    {
        $name = ltrim($name, '\/');
        $name = str_replace('/', '\\', $name);

        $namespace = $this->option('namespace');
        if (empty($namespace)) {
            $namespace = $this->getDefaultNamespace($this->rootNamespace());
        }

        return $namespace . '\\' . $name;
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $this->getConfig()['namespace'] ?? $rootNamespace;
    }

    /**
     * Determine if the class already exists.
     */
    protected function alreadyExists(string $rawName): bool
    {
        return is_file($this->getPath($this->qualifyClass($rawName)));
    }

    /**
     * Get the destination class path.
     *
     * Supports the --path option to override the default path.
     */
    protected function getPath(string $name): string
    {
        if ($path = $this->option('path')) {
            $className = Str::afterLast($name, '\\');

            if (str_starts_with($path, '/')) {
                return rtrim($path, '/') . '/' . $className . '.php';
            }

            return BASE_PATH . '/' . trim($path, '/') . '/' . $className . '.php';
        }

        $name = Str::replaceFirst($this->rootNamespace(), '', $name);

        return $this->app->path() . '/' . str_replace('\\', '/', $name) . '.php';
    }

    /**
     * Build the directory for the class if necessary.
     */
    protected function makeDirectory(string $path): string
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        return $path;
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass(string $name): string
    {
        $stub = file_get_contents($this->getStub());

        return $this->replaceNamespace($stub, $name)->replaceClass($stub, $name);
    }

    /**
     * Replace the namespace for the given stub.
     */
    protected function replaceNamespace(string &$stub, string $name): static
    {
        $stub = str_replace(
            ['%NAMESPACE%'],
            [$this->getNamespace($name)],
            $stub
        );

        return $this;
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass(string $stub, string $name): string
    {
        $class = str_replace($this->getNamespace($name) . '\\', '', $name);

        return str_replace('%CLASS%', $class, $stub);
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Whether force to rewrite.'],
            ['namespace', 'N', InputOption::VALUE_OPTIONAL, 'The namespace for class.', null],
            ['path', null, InputOption::VALUE_OPTIONAL, 'The location where the file should be created.', null],
        ];
    }

    /**
     * Get the custom config for generator.
     */
    protected function getConfig(): array
    {
        $class = Str::afterLast(static::class, '\\');
        $class = Str::replaceLast('Command', '', $class);
        $key = 'devtool.generator.' . Str::snake($class, '.');

        return $this->app->make('config')->get($key) ?? [];
    }

    /**
     * Get the editor file opener URL by its name.
     */
    protected function getEditorUrl(string $ide): string
    {
        return match ($ide) {
            'sublime' => 'subl://open?url=file://%s',
            'textmate' => 'txmt://open?url=file://%s',
            'cursor' => 'cursor://file/%s',
            'emacs' => 'emacs://open?url=file://%s',
            'macvim' => 'mvim://open/?url=file://%s',
            'phpstorm' => 'phpstorm://open?file=%s',
            'idea' => 'idea://open?file=%s',
            'vscode' => 'vscode://file/%s',
            'vscode-insiders' => 'vscode-insiders://file/%s',
            'vscode-remote' => 'vscode://vscode-remote/%s',
            'vscode-insiders-remote' => 'vscode-insiders://vscode-remote/%s',
            'atom' => 'atom://core/open/file?filename=%s',
            'nova' => 'nova://core/open/file?filename=%s',
            'netbeans' => 'netbeans://open/?f=%s',
            'xdebug' => 'xdebug://%s',
            default => '',
        };
    }

    /**
     * Open the generated file with the configured IDE.
     */
    protected function openWithIde(string $path): void
    {
        $ide = (string) $this->app->make('config')->get('devtool.ide');
        $openEditorUrl = $this->getEditorUrl($ide);

        if (! $openEditorUrl) {
            return;
        }

        $url = sprintf($openEditorUrl, $path);
        match (PHP_OS_FAMILY) {
            'Windows' => exec('explorer ' . $url),
            'Linux' => exec('xdg-open ' . $url),
            'Darwin' => exec('open ' . $url),
            default => null,
        };
    }
}
