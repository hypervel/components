<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

abstract class GeneratorCommand extends Command
{
    /**
     * The type of class being generated.
     */
    protected string $type = '';

    /**
     * Reserved names that cannot be used for generation.
     *
     * @var string[]
     */
    protected array $reservedNames = [
        '__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 'case',
        'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do',
        'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final',
        'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list',
        'match', 'namespace', 'new', 'or', 'parent', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'require_once', 'return', 'self', 'static',
        'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'while', 'xor',
        'yield', '__CLASS__', '__DIR__', '__FILE__', '__FUNCTION__', '__LINE__',
        '__METHOD__', '__NAMESPACE__', '__TRAIT__',
    ];

    /**
     * Whether to execute in a coroutine environment.
     */
    protected bool $coroutine = false;

    /**
     * Get the stub file for the generator.
     */
    abstract protected function getStub(): string;

    /**
     * Get the default namespace for the class.
     */
    abstract protected function getDefaultNamespace(): string;

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
     */
    protected function qualifyClass(string $name): string
    {
        $name = ltrim($name, '\/');
        $name = str_replace('/', '\\', $name);

        $namespace = $this->option('namespace');
        if (empty($namespace)) {
            $namespace = $this->getDefaultNamespace();
        }

        return $namespace . '\\' . $name;
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
     * Get the root namespace for the class.
     */
    protected function rootNamespace(): string
    {
        return $this->app->getNamespace();
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
     * Get the full namespace for a given class, without the class name.
     */
    protected function getNamespace(string $name): string
    {
        return trim(implode('\\', array_slice(explode('\\', $name), 0, -1)), '\\');
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
     * Alphabetically sort the imports for the given stub.
     */
    protected function sortImports(string $stub): string
    {
        if (preg_match('/(?P<imports>(?:^use [^;{]+;$\n?)+)/m', $stub, $match)) {
            $imports = explode("\n", trim($match['imports']));

            sort($imports);

            return str_replace(trim($match['imports']), implode("\n", $imports), $stub);
        }

        return $stub;
    }

    /**
     * Get the desired class name from the input.
     */
    protected function getNameInput(): string
    {
        return trim($this->argument('name'));
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the class'],
        ];
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
     * Check whether the given name is reserved.
     */
    protected function isReservedName(string $name): bool
    {
        return in_array(
            strtolower($name),
            array_map('strtolower', $this->reservedNames)
        );
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
     * Open resulted file path with the configured IDE.
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
