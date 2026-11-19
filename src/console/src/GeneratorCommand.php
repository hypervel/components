<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Closure;
use Hypervel\Console\Concerns\CreatesMatchingTest;
use Hypervel\Console\Concerns\FindsAvailableModels;
use Hypervel\Contracts\Console\PromptsForMissingInput;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use ParseError;
use PhpToken;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;

abstract class GeneratorCommand extends Command implements PromptsForMissingInput
{
    use FindsAvailableModels;

    /**
     * Type names that PHP reserves without making them keywords.
     */
    protected const array RESERVED_TYPE_NAMES = [
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'parent', 'self', 'string', 'true', 'void',
    ];

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * The type of class being generated.
     */
    protected string $type = '';

    /**
     * Whether to execute in a coroutine environment.
     */
    protected bool $coroutine = false;

    /**
     * Reserved names that cannot be used for generation.
     *
     * @var string[]
     */
    protected array $reservedNames = [
        '__halt_compiler',
        'abstract',
        'and',
        'array',
        'as',
        'break',
        'callable',
        'case',
        'catch',
        'class',
        'clone',
        'const',
        'continue',
        'declare',
        'default',
        'die',
        'do',
        'echo',
        'else',
        'elseif',
        'empty',
        'enddeclare',
        'endfor',
        'endforeach',
        'endif',
        'endswitch',
        'endwhile',
        'enum',
        'eval',
        'exit',
        'extends',
        'false',
        'final',
        'finally',
        'fn',
        'for',
        'foreach',
        'function',
        'global',
        'goto',
        'if',
        'implements',
        'include',
        'include_once',
        'instanceof',
        'insteadof',
        'interface',
        'isset',
        'list',
        'match',
        'namespace',
        'new',
        'or',
        'parent',
        'print',
        'private',
        'protected',
        'public',
        'readonly',
        'require',
        'require_once',
        'return',
        'self',
        'static',
        'switch',
        'throw',
        'trait',
        'true',
        'try',
        'unset',
        'use',
        'var',
        'while',
        'xor',
        'yield',
        '__CLASS__',
        '__DIR__',
        '__FILE__',
        '__FUNCTION__',
        '__LINE__',
        '__METHOD__',
        '__NAMESPACE__',
        '__TRAIT__',
    ];

    /**
     * Create a new generator command instance.
     */
    public function __construct(Filesystem $files = new Filesystem)
    {
        parent::__construct();

        if (isset(class_uses_recursive($this)[CreatesMatchingTest::class])) {
            $this->addTestOptions(); // @phpstan-ignore method.notFound (provided by CreatesMatchingTest, checked above)
        }

        $this->addPathAndNamespaceOptions();

        $this->files = $files;
    }

    /**
     * Add the --target-path and --target-namespace options to the command definition.
     *
     * Added via getDefinition() rather than getOptions() so they are always
     * available regardless of whether a subclass overrides getOptions().
     */
    protected function addPathAndNamespaceOptions(): void
    {
        $definition = $this->getDefinition();

        $definition->addOption(new InputOption(
            'target-path',
            null,
            InputOption::VALUE_OPTIONAL,
            'The location where the file should be created'
        ));

        $definition->addOption(new InputOption(
            'target-namespace',
            'N',
            InputOption::VALUE_OPTIONAL,
            'The namespace for the class'
        ));
    }

    /**
     * Get the stub file for the generator.
     */
    abstract protected function getStub(): string;

    /**
     * Execute the console command.
     *
     * @throws FileNotFoundException
     */
    public function handle(): bool|int
    {
        // First we need to ensure that the given name is not a reserved word within the PHP
        // language and that the class name will actually be valid. If it is not valid we
        // can error now and prevent from polluting the filesystem using invalid files.
        // Unlike Laravel, this fails so the command exits with an error and generates no companion files.
        if ($this->isReservedName($this->getNameInput())) {
            $this->fail('The name "' . $this->getNameInput() . '" is reserved by PHP.');
        }

        $name = $this->qualifyClass($this->getNameInput());

        $path = $this->getPath($name);

        // Next, We will check to see if the class already exists. If it does, we don't want
        // to create the class and overwrite the user's code. So, we will bail out so the
        // code is untouched. Otherwise, we will continue generating this class' files.
        if ((! $this->hasOption('force')
             || ! $this->option('force'))
             && $this->alreadyExists($this->getNameInput())) {
            $this->components->error($this->type . ' already exists.');

            return false;
        }

        // Next, we will generate the path to the location where this class' file should get
        // written. Then, we will build the class and make the proper replacements on the
        // stub files so that it gets the correctly formatted namespace and class name.
        $contents = $this->sortImports($this->buildClass($name));

        $this->ensureClassNamesAreValid($contents);

        $this->makeDirectory($path);

        $this->replaceFile($path, $contents);

        $info = $this->type;

        if (isset(class_uses_recursive($this)[CreatesMatchingTest::class])) {
            $this->handleTestCreation($path); // @phpstan-ignore method.notFound (provided by CreatesMatchingTest, checked above)
        }

        if (windows_os()) {
            $path = str_replace('/', '\\', $path);
        }

        $this->components->info(sprintf('%s [%s] created successfully.', $info, $path));

        return self::SUCCESS;
    }

    /**
     * Parse the class name and format according to the root namespace.
     */
    protected function qualifyClass(string $name): string
    {
        $name = ltrim($name, '\/');

        $name = str_replace('/', '\\', $name);

        $targetNamespace = $this->option('target-namespace');

        if (! empty($targetNamespace)) {
            return $targetNamespace . '\\' . $name;
        }

        $rootNamespace = $this->rootNamespace();

        if (Str::startsWith($name, $rootNamespace)) {
            return $name;
        }

        return $this->qualifyClass(
            $this->getDefaultNamespace(trim($rootNamespace, '\\')) . '\\' . $name
        );
    }

    /**
     * Qualify the given model class base name.
     *
     * @return class-string
     */
    protected function qualifyModel(string $model): string
    {
        $model = ltrim($model, '\/');

        $model = str_replace('/', '\\', $model);

        $rootNamespace = $this->rootNamespace();

        if (Str::startsWith($model, $rootNamespace)) {
            return $model;
        }

        return is_dir(app_path('Models'))
            ? $rootNamespace . 'Models\\' . $model
            : $rootNamespace . $model;
    }

    // Laravel's deprecated possibleModels() alias is omitted; use findAvailableModels().

    /**
     * Get a list of possible event names.
     *
     * @return array<int, string>
     */
    protected function possibleEvents(): array
    {
        $eventPath = app_path('Events');

        if (! is_dir($eventPath)) {
            return [];
        }

        return (new Collection(Finder::create()->files()->depth(0)->in($eventPath)))
            ->map(fn ($file) => $file->getBasename('.php'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace;
    }

    /**
     * Determine if the class already exists.
     */
    protected function alreadyExists(string $rawName): bool
    {
        return $this->files->exists($this->getPath($this->qualifyClass($rawName)));
    }

    /**
     * Get the destination class path.
     */
    protected function getPath(string $name): string
    {
        if ($targetPath = $this->option('target-path')) {
            $className = Str::afterLast($name, '\\');

            if (str_starts_with($targetPath, '/')) {
                return rtrim($targetPath, '/') . '/' . $className . '.php';
            }

            return $this->hypervel->basePath(trim($targetPath, '/') . '/' . $className . '.php');
        }

        $name = Str::replaceFirst($this->rootNamespace(), '', $name);

        return $this->hypervel->path() . '/' . str_replace('\\', '/', $name) . '.php';
    }

    /**
     * Build the directory for the class if necessary.
     */
    protected function makeDirectory(string $path): string
    {
        $this->files->ensureDirectoryExists(dirname($path), 0777);

        return $path;
    }

    /**
     * Replace a generated file while preserving its permissions.
     */
    protected function replaceFile(string $path, string $contents): void
    {
        $mode = null;

        if ($this->files->exists($path)) {
            $permissions = $this->files->chmod($path);

            if ($permissions === false) {
                throw new RuntimeException("Unable to determine the permissions for [{$path}].");
            }

            $mode = octdec($permissions);
        }

        $this->files->replace($path, $contents, $mode);
    }

    /**
     * Build the class with the given name.
     *
     * @throws FileNotFoundException
     */
    protected function buildClass(string $name): string
    {
        $stub = $this->files->get($this->getStub());

        return $this->replaceNamespace($stub, $name)->replaceClass($stub, $name);
    }

    /**
     * Replace the namespace for the given stub.
     *
     * @return $this
     */
    protected function replaceNamespace(string &$stub, string $name): static
    {
        $searches = [
            ['DummyNamespace', 'DummyRootNamespace', 'NamespacedDummyUserModel'],
            ['{{ namespace }}', '{{ rootNamespace }}', '{{ namespacedUserModel }}'],
            ['{{namespace}}', '{{rootNamespace}}', '{{namespacedUserModel}}'],
        ];

        foreach ($searches as $search) {
            $stub = str_replace(
                $search,
                [$this->getNamespace($name), $this->rootNamespace(), $this->userProviderModel()],
                $stub
            );
        }

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

        return str_replace(['DummyClass', '{{ class }}', '{{class}}'], $class, $stub);
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
        $name = trim($this->argument('name'));

        if (Str::endsWith($name, '.php')) {
            return Str::substr($name, 0, -4);
        }

        return $name;
    }

    /**
     * Get the root namespace for the class.
     */
    protected function rootNamespace(): string
    {
        return $this->hypervel->getNamespace();
    }

    /**
     * Get the model for the default guard's user provider.
     */
    protected function userProviderModel(): ?string
    {
        // Best-effort guess: console may run without the auth package installed.
        if (! $this->hypervel->bound('auth')) {
            return null;
        }

        $config = $this->hypervel->make('config');

        if (! $config->has('auth.defaults.guard')) {
            return null;
        }

        $provider = $config->get('auth.guards.' . $this->hypervel->make('auth')->getDefaultDriver() . '.provider');

        return $config->get("auth.providers.{$provider}.model");
    }

    /**
     * Check whether the given name is reserved.
     */
    protected function isReservedName(string $name): bool
    {
        $name = strtolower($name);

        return array_any($this->reservedNames, fn ($reservedName) => strtolower($reservedName) === $name);
    }

    /**
     * Ensure the classes declared in the generated code have names PHP accepts.
     *
     * Unlike Laravel, this rejects reserved names that only appear in the generated class, such as a nested
     * School/Class, and names imported into the class's namespace. The command fails before writing the file,
     * even with --force, instead of creating one that PHP refuses to load.
     */
    protected function ensureClassNamesAreValid(string $contents): void
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($contents),
            fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        $namespaces = [['name' => '', 'imports' => [], 'classes' => []]];
        $depth = 0;
        $importDepth = 0;

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;

            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;
            } elseif ($token->is('}')) {
                --$depth;
            } elseif ($token->is(T_NAMESPACE)) {
                $name = $next?->is([T_STRING, T_NAME_QUALIFIED]) ? $next->text : '';
                $namespaces[] = ['name' => $name, 'imports' => [], 'classes' => []];
                // Imports sit directly inside a braced namespace; deeper use statements import traits.
                $importDepth = ($tokens[$index + ($name === '' ? 1 : 2)] ?? null)?->is('{') ? $depth + 1 : $depth;
            } elseif ($token->is(T_USE) && $depth === $importDepth && ! $next?->is(['(', T_FUNCTION, T_CONST])) {
                $namespaces[array_key_last($namespaces)]['imports'] += $this->importedClasses($tokens, $index + 1);
            } elseif ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $next?->is(T_STRING)) {
                if (in_array(strtolower($next->text), self::RESERVED_TYPE_NAMES, true)) {
                    $this->fail('The name "' . $next->text . '" is reserved by PHP.');
                }

                $namespaces[array_key_last($namespaces)]['classes'][strtolower($next->text)] = $next->text;
            } elseif (
                // PHP tokenizes enum as a name when extends or implements follows it.
                ($token->is([T_CLASS, T_INTERFACE, T_TRAIT]) || strcasecmp($token->text, 'enum') === 0)
                && $next !== null
                && ! $next->is(T_STRING)
                && preg_match('/^[a-z_\x80-\xff]/i', $next->text) === 1
            ) {
                // A keyword here either continues an anonymous class, such as extends, or is a name PHP's grammar rejects.
                try {
                    PhpToken::tokenize($contents, TOKEN_PARSE);
                } catch (ParseError $exception) {
                    if ($exception->getLine() === $next->line) {
                        $this->fail('The name "' . $next->text . '" is reserved by PHP.');
                    }
                }
            }
        }

        foreach ($namespaces as ['name' => $namespace, 'imports' => $imports, 'classes' => $classes]) {
            foreach (array_intersect_key($imports, $classes) as $key => $import) {
                if (strcasecmp($import, ltrim("{$namespace}\\{$classes[$key]}", '\\')) !== 0) {
                    $this->fail(sprintf('The class name "%s" conflicts with the imported [%s] class.', $classes[$key], $import));
                }
            }
        }
    }

    /**
     * Get the classes imported by the use statement starting at the given token, keyed by their lowercase alias.
     *
     * @param list<PhpToken> $tokens
     * @return array<string, string>
     */
    protected function importedClasses(array $tokens, int $index): array
    {
        $imports = [];
        $prefix = '';
        $name = '';
        $alias = null;
        $isClass = true;

        for (; ($token = $tokens[$index] ?? null) !== null; ++$index) {
            if ($token->is('{')) {
                [$prefix, $name] = [$name, ''];
            } elseif ($token->is(T_AS)) {
                $alias = $tokens[++$index]->text;
            } elseif ($token->is([T_FUNCTION, T_CONST])) {
                $isClass = false;
            } elseif (! $token->is([',', '}', ';'])) {
                $name .= $token->text;
            } else {
                if ($isClass && $name !== '') {
                    $imports[strtolower($alias ?? class_basename($name))] = ltrim($prefix . $name, '\\');
                }

                if ($token->is(';')) {
                    return $imports;
                }

                [$name, $alias, $isClass] = ['', null, true];
            }
        }

        return $imports;
    }

    /**
     * Get the first view directory path from the application configuration.
     */
    protected function viewPath(string $path = ''): string
    {
        $views = $this->hypervel->make('config')->array('view.paths')[0]
            ?? $this->hypervel->basePath('resources/views');

        return $views . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the ' . strtolower($this->type)],
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
            'name' => [
                'What should the ' . strtolower($this->type) . ' be named?',
                match ($this->type) {
                    'Cast' => 'E.g. Json',
                    'Channel' => 'E.g. OrderChannel',
                    'Console command' => 'E.g. SendEmails',
                    'Component' => 'E.g. Alert',
                    'Controller' => 'E.g. UserController',
                    'Event' => 'E.g. PodcastProcessed',
                    'Exception' => 'E.g. InvalidOrderException',
                    'Factory' => 'E.g. PostFactory',
                    'Job' => 'E.g. ProcessPodcast',
                    'Listener' => 'E.g. SendPodcastNotification',
                    'Mailable' => 'E.g. OrderShipped',
                    'Middleware' => 'E.g. EnsureTokenIsValid',
                    'Model' => 'E.g. Flight',
                    'Notification' => 'E.g. InvoicePaid',
                    'Observer' => 'E.g. UserObserver',
                    'Policy' => 'E.g. PostPolicy',
                    'Provider' => 'E.g. ElasticServiceProvider',
                    'Request' => 'E.g. StorePodcastRequest',
                    'Resource' => 'E.g. UserResource',
                    'Rule' => 'E.g. Uppercase',
                    'Scope' => 'E.g. TrendingScope',
                    'Seeder' => 'E.g. UserSeeder',
                    'Test' => 'E.g. UserTest',
                    default => '',
                },
            ],
        ];
    }
}
