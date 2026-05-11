<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\Route as BaseRoute;
use Hypervel\Routing\Router;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\URL;
use Hypervel\Support\Str;
use Hypervel\View\Factory;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Prompts\info;

#[AsCommand(name: 'wayfinder:generate')]
class GenerateCommand extends Command
{
    protected ?string $signature = 'wayfinder:generate {--path=} {--skip-actions} {--skip-routes} {--with-form}';

    private ?string $forcedScheme = null;

    private ?string $forcedRoot = null;

    private array $urlDefaults = [];

    private string $pathDirectory = 'actions';

    /**
     * Buffered content per generated file path.
     *
     * @var array<string, string[]>
     */
    private array $content = [];

    /**
     * Imports array where the key is the generated file path and the value is an array of imports.
     * Each import is an array where the first element is the import path and the second element is an array of imported items.
     *
     * @var array<string, array<string, string[]>>
     */
    private array $imports = [];

    /**
     * Create a new GenerateCommand instance.
     */
    public function __construct(
        private Filesystem $files,
        private Router $router,
        private Factory $view,
        private UrlGenerator $url,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->view->addNamespace('wayfinder', __DIR__ . '/../resources');
        $this->view->addExtension('blade.ts', 'blade');

        $this->forcedScheme = (new ReflectionProperty($this->url, 'forceScheme'))->getValue($this->url);
        $this->forcedRoot = (new ReflectionProperty($this->url, 'forcedRoot'))->getValue($this->url);

        $globalUrlDefaults = collect(URL::getDefaultParameters())->map(fn (mixed $v) => is_scalar($v) || is_null($v) ? $v : '');

        $routes = collect($this->router->getRoutes())->map(function (BaseRoute $route) use ($globalUrlDefaults) {
            $defaults = collect($this->router->gatherRouteMiddleware($route))->map(function (mixed $middleware): array {
                if ($middleware instanceof Closure) {
                    return [];
                }

                $this->urlDefaults[$middleware] ??= $this->getDefaultsForMiddleware($middleware);

                return $this->urlDefaults[$middleware];
            })->flatMap(fn (array $r) => $r);

            return new Route($route, $globalUrlDefaults->merge($defaults), $this->forcedScheme, $this->forcedRoot);
        });

        $this->writeWayfinderHelperFile();

        if (! $this->option('skip-actions')) {
            $controllers = $routes->filter(fn (Route $route) => $route->hasController())->groupBy(fn (Route $route) => $route->dotNamespace());

            $controllers->undot()->each($this->writeBarrelFiles(...));
            $controllers->each($this->writeControllerFile(...));

            $this->pruneStaleFiles($this->base(), $this->writeContent());

            info('[Wayfinder] Generated actions in ' . $this->base());
        }

        $this->pathDirectory = 'routes';

        if (! $this->option('skip-routes')) {
            $named = $routes->filter(fn (Route $route) => $route->name() !== null)->groupBy(fn (Route $route) => $route->name());

            $named->each($this->writeNamedFile(...));
            $named->undot()->each($this->writeBarrelFiles(...));

            $this->pruneStaleFiles($this->base(), $this->writeContent());

            info('[Wayfinder] Generated routes in ' . $this->base());
        }

        return Command::SUCCESS;
    }

    /**
     * Copy the Wayfinder runtime helper into the generated output tree.
     */
    private function writeWayfinderHelperFile(): void
    {
        $previousPathDirectory = $this->pathDirectory;
        $this->pathDirectory = 'wayfinder';

        $this->files->ensureDirectoryExists($this->base());

        $source = __DIR__ . '/../resources/js/wayfinder.ts';
        $destination = join_paths($this->base(), 'index.ts');

        $this->writeContentIfChanged($destination, $this->files->get($source));

        $this->pathDirectory = $previousPathDirectory;
    }

    /**
     * Append a content fragment for the given output path, de-duplicating exact matches.
     */
    private function appendContent(string $path, string $content): void
    {
        $this->content[$path] ??= [];

        if (! in_array($content, $this->content[$path])) {
            $this->content[$path][] = $content;
        }
    }

    /**
     * Prepend a content fragment to the buffered output for the given path.
     */
    private function prependContent(string $path, string $content): void
    {
        $this->content[$path] ??= [];

        array_unshift($this->content[$path], $content);
    }

    /**
     * Flush buffered content to disk and return the paths that were written.
     *
     * @return string[] paths that were written
     */
    private function writeContent(): array
    {
        $written = [];

        foreach ($this->content as $path => $content) {
            $this->files->ensureDirectoryExists(dirname($path));

            $body = TypeScript::cleanUp(implode(PHP_EOL, $content));

            if (isset($this->imports[$path])) {
                $importLines = collect($this->imports[$path])
                    ->map(fn (array $imports, string $key) => 'import { ' . implode(', ', array_unique($imports)) . " } from '{$key}'")
                    ->implode(PHP_EOL);

                $body = $importLines . PHP_EOL . $body;
            }

            $this->writeContentIfChanged($path, $body);

            $written[] = $path;
        }

        $this->content = [];
        $this->imports = [];

        return $written;
    }

    /**
     * Write the file only when its contents differ from what's on disk.
     */
    private function writeContentIfChanged(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));

        if (! $this->files->exists($path) || $this->files->get($path) !== $content) {
            $this->files->put($path, $content);
        }
    }

    /**
     * Remove any files under $base that weren't written during this run.
     *
     * @param string[] $writtenPaths
     */
    private function pruneStaleFiles(string $base, array $writtenPaths): void
    {
        if (! $this->files->isDirectory($base)) {
            return;
        }

        $kept = collect($writtenPaths)->map(fn (string $path) => realpath($path) ?: $path)->flip();

        foreach ($this->files->allFiles($base) as $file) {
            $path = $file->getPathname();

            if (! $kept->has(realpath($path) ?: $path)) {
                $this->files->delete($path);
            }
        }

        $this->pruneEmptyDirectories($base);
    }

    /**
     * Recursively delete directories left empty after pruning.
     */
    private function pruneEmptyDirectories(string $dir): void
    {
        if (! $this->files->isDirectory($dir)) {
            return;
        }

        foreach ($this->files->directories($dir) as $sub) {
            $this->pruneEmptyDirectories($sub);
        }

        if (empty($this->files->files($dir)) && empty($this->files->directories($dir))) {
            $this->files->deleteDirectory($dir);
        }
    }

    /**
     * Write the TypeScript file for a single controller's routes.
     *
     * @param Collection<int, Route> $routes
     */
    private function writeControllerFile(Collection $routes, string $namespace): void
    {
        $path = join_paths($this->base(), ...explode('.', $namespace)) . '.ts';

        $this->appendCommonImports($routes, $path, $namespace);

        $routes->groupBy(fn (Route $route) => $route->method())->each(function (Collection $methodRoutes) use ($path) {
            if ($methodRoutes->count() === 1) {
                $this->writeControllerMethodExport($methodRoutes->first(), $path);

                return;
            }

            $this->writeMultiRouteControllerMethodExport($methodRoutes, $path);
        });

        [$invokable, $methods] = $routes->partition(fn (Route $route) => $route->hasInvokableController());

        $defaultExport = $invokable->isNotEmpty() ? $invokable->first()->jsMethod() : last(explode('.', $namespace));

        if ($invokable->isEmpty()) {
            $exportedMethods = $methods->map(fn (Route $route) => $route->jsMethod());
            $reservedMethods = $methods->filter(fn (Route $route) => $route->originalJsMethod() !== $route->jsMethod())->map(fn (Route $route) => TypeScript::quoteIfNeeded($route->originalJsMethod()) . ': ' . $route->jsMethod());
            $exportedMethods = $exportedMethods->merge($reservedMethods);

            $methodProps = "const {$defaultExport} = { ";
            $methodProps .= $exportedMethods->unique()->implode(', ');
            $methodProps .= ' }';
        } else {
            $methodProps = $methods->map(fn (Route $route) => $defaultExport . '.' . $route->jsMethod() . ' = ' . $route->jsMethod())->unique()->implode(PHP_EOL);
        }

        $this->appendContent($path, <<<JAVASCRIPT
        {$methodProps}

        export default {$defaultExport}
        JAVASCRIPT);
    }

    /**
     * Return alternate names for reserved param identifiers that collide with the method name.
     *
     * @return array{args: string, options: string, parsedArgs: string}
     */
    private function safeParamNames(string $method): array
    {
        $reserved = [
            'args' => 'routeArgs',
            'options' => 'routeOptions',
            'parsedArgs' => 'routeParsedArgs',
        ];

        $params = array_map(fn (string $default, string $name) => $method === $name ? $default : $name, $reserved, array_keys($reserved));

        return array_combine(array_keys($reserved), $params);
    }

    /**
     * Render the multi-route method template for routes sharing a JS method name.
     *
     * @param Collection<int, Route> $routes
     */
    private function writeMultiRouteControllerMethodExport(Collection $routes, string $path): void
    {
        $isInvokable = $routes->first()->hasInvokableController();
        $method = $routes->first()->jsMethod();

        $this->appendContent($path, $this->view->make('wayfinder::multi-method', [
            'method' => $method,
            'original_method' => $routes->first()->originalJsMethod(),
            'path' => $routes->first()->controllerPath(),
            'line' => $routes->first()->controllerMethodLineNumber(),
            'controller' => $routes->first()->controller(),
            'isInvokable' => $isInvokable,
            'shouldExport' => ! $isInvokable,
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
            'routes' => $routes->map(fn (Route $r) => [
                'method' => $r->jsMethod(),
                'tempMethod' => $r->jsMethod() . hash('xxh128', $r->uri()),
                'parameters' => $r->parameters(),
                'verbs' => $r->verbs(),
                'uri' => $r->uri(),
            ]),
        ])->render());
    }

    /**
     * Render the single-method template for a controller route.
     */
    private function writeControllerMethodExport(Route $route, string $path): void
    {
        $method = $route->jsMethod();

        $this->appendContent($path, $this->view->make('wayfinder::method', [
            'controller' => $route->controller(),
            'method' => $method,
            'original_method' => $route->originalJsMethod(),
            'isInvokable' => $route->hasInvokableController(),
            'shouldExport' => ! $route->hasInvokableController(),
            'path' => $route->controllerPath(),
            'line' => $route->controllerMethodLineNumber(),
            'parameters' => $route->parameters(),
            'verbs' => $route->verbs(),
            'uri' => $route->uri(),
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
        ])->render());
    }

    /**
     * Write the TypeScript file for a named route group.
     *
     * @param Collection<int, Route> $routes
     */
    private function writeNamedFile(Collection $routes, string $namespace): void
    {
        $parts = explode('.', $namespace);
        array_pop($parts);
        $parts[] = 'index';

        $path = join_paths($this->base(), ...$parts) . '.ts';

        $this->appendCommonImports($routes, $path, $namespace);

        $routes->each(fn (Route $route) => $this->writeNamedMethodExport($route, $path));
    }

    /**
     * Record the runtime helper imports needed by the generated file.
     *
     * @param Collection<int, Route> $routes
     */
    private function appendCommonImports(Collection $routes, string $path, string $namespace): void
    {
        $imports = ['queryParams', 'type RouteQueryOptions', 'type RouteDefinition'];

        if ($this->option('with-form') === true) {
            $imports[] = 'type RouteFormDefinition';
        }

        if ($routes->contains(fn (Route $route) => $route->parameters()->isNotEmpty())) {
            $imports[] = 'applyUrlDefaults';
        }

        if ($routes->contains(fn (Route $route) => $route->parameters()->contains(fn (Parameter $parameter) => $parameter->optional))) {
            $imports[] = 'validateParameters';
        }

        $importBase = str_repeat('/..', substr_count($namespace, '.') + 1);
        $pathKey = ".{$importBase}/wayfinder";

        $this->imports[$path] ??= [];
        $this->imports[$path][$pathKey] = [
            ...($this->imports[$path][$pathKey] ?? []),
            ...$imports,
        ];
    }

    /**
     * Render the named-route export for a single route.
     */
    private function writeNamedMethodExport(Route $route, string $path): void
    {
        $method = $route->namedMethod();

        $this->appendContent($path, $this->view->make('wayfinder::method', [
            'controller' => $route->controller(),
            'method' => $method,
            'original_method' => $route->originalJsMethod(),
            'isInvokable' => $route->hasInvokableController(),
            'shouldExport' => true,
            'path' => $route->controllerPath(),
            'line' => $route->controllerMethodLineNumber(),
            'parameters' => $route->parameters(),
            'verbs' => $route->verbs(),
            'uri' => $route->uri(),
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
        ])->render());
    }

    /**
     * Recursively write barrel index.ts files that re-export their children.
     */
    private function writeBarrelFiles(array|Collection $children, string $parent): void
    {
        $children = collect($children);

        if (array_is_list($children->all())) {
            return;
        }

        $indexPath = join_paths($this->base(), $parent, 'index.ts');
        $keysWithGrandkids = $children->filter(fn (mixed $grandChildren) => ! array_is_list(collect($grandChildren)->all()));

        $childKeys = $children->keys()->mapWithKeys(function (int|string $child) use ($indexPath, $keysWithGrandkids) {
            $child = (string) $child;
            $safeMethod = TypeScript::safeMethod($child, 'Method');
            $safe = $safeMethod;

            if ($keysWithGrandkids->has($child)) {
                foreach ($this->content[$indexPath] ?? [] as $content) {
                    if (str_contains((string) $content, 'const ' . $safeMethod . ' =')) {
                        $safe .= str(hash('xxh128', $safe))->substr(0, 6)->ucfirst();
                    }
                }
            }

            return [
                $child => [
                    'safeMethod' => $safeMethod,
                    'safe' => $safe,
                    'safeAssign' => "Object.assign({$safeMethod}, {$safe})",
                    'normalized' => str($child)->whenContains('-', fn ($s) => $s->camel())->toString(),
                ],
            ];
        });

        if (! ($this->content[$indexPath] ?? false)) {
            $imports = $childKeys->filter(fn (array $_, string $key) => $key !== 'index')->map(fn (array $alias, string $key) => "import {$alias['safe']} from './{$key}'")->implode(PHP_EOL);
        } else {
            $imports = $childKeys->only($keysWithGrandkids->keys())->map(fn (array $alias, string $key) => "import {$alias['safe']} from './{$key}'")->implode(PHP_EOL);
        }

        if ($imports) {
            $this->prependContent($indexPath, $imports);
        }

        $keys = $childKeys->map(fn (array $alias, string $key) => str_repeat(' ', 4) . implode(': ', array_unique([$alias['normalized'], $alias['safeAssign']])))->implode(', ' . PHP_EOL);

        $varExport = TypeScript::safeMethod(Str::afterLast($parent, DIRECTORY_SEPARATOR), 'Method');
        $existingVars = $childKeys
            ->flatMap(fn (array $alias) => [$alias['safeMethod'], $alias['safe']])
            ->filter()
            ->unique()
            ->values();

        if ($existingVars->contains($varExport)) {
            $baseExport = $varExport . 'Namespace';
            $varExport = TypeScript::safeMethod($baseExport, 'Method');
            $suffix = 2;

            while ($existingVars->contains($varExport)) {
                $varExport = TypeScript::safeMethod($baseExport . $suffix, 'Method');
                ++$suffix;
            }
        }

        $this->appendContent($indexPath, <<<JAVASCRIPT


                const {$varExport} = {
                {$keys},
                }

                export default {$varExport}
                JAVASCRIPT);

        $children->each(fn (mixed $grandChildren, int|string $child) => $this->writeBarrelFiles($grandChildren, join_paths($parent, (string) $child)));
    }

    /**
     * Return the resolved output base directory for the current path mode.
     */
    private function base(): string
    {
        $base = $this->option('path') ?? join_paths(resource_path(), 'js');

        return join_paths($base, $this->pathDirectory);
    }

    /**
     * Inspect a middleware class for URL::defaults() calls and return their array contents.
     *
     * @return array<string, int|string>
     */
    private function getDefaultsForMiddleware(string $middleware): array
    {
        if (! class_exists($middleware)) {
            return [];
        }

        $reflection = new ReflectionClass($middleware);

        if (! $reflection->hasMethod('handle')) {
            return [];
        }

        $method = $reflection->getMethod('handle');

        // Get the file name and line numbers
        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        // Read the file and extract the method contents
        $lines = file($fileName);
        $methodContents = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        if (! str_contains($methodContents, 'URL::defaults')) {
            return [];
        }

        $methodContents = str($methodContents)->after('{')->beforeLast('}')->trim()->toString();

        return $this->extractUrlDefaults($methodContents);
    }

    /**
     * Tokenise the middleware method body and extract the URL::defaults() array literal.
     *
     * @return array<string, int|string>
     */
    private function extractUrlDefaults(string $methodContents): array
    {
        $tokens = token_get_all('<?php ' . $methodContents);
        $foundUrlFacade = false;
        $defaults = [];
        $inArray = false;

        foreach ($tokens as $index => $token) {
            if (is_array($token) && token_name($token[0]) === 'T_STRING') {
                if (
                    $token[1] === 'URL'
                    && is_array($tokens[$index + 1])
                    && $tokens[$index + 1][1] === '::'
                    && is_array($tokens[$index + 2])
                    && $tokens[$index + 2][1] === 'defaults'
                ) {
                    $foundUrlFacade = true;
                }
            }

            if (! $foundUrlFacade) {
                continue;
            }

            if ((is_array($token) && $token[0] === T_ARRAY) || $token === '[') {
                $inArray = true;
            }

            // If we are in an array context and the token is a string (key)
            if (! $inArray) {
                continue;
            }

            if (is_array($token) && $token[0] === T_DOUBLE_ARROW) {
                $count = 1;
                $previousToken = $tokens[$index - $count];

                // Work backwards to get the key
                while (is_array($previousToken) && $previousToken[0] === T_WHITESPACE) {
                    ++$count;
                    $previousToken = $tokens[$index - $count];
                }

                $valueToken = $tokens[$index + 1];
                $count = 1;

                // Work backwards to get the key
                while (is_array($valueToken) && $valueToken[0] === T_WHITESPACE) {
                    ++$count;
                    $valueToken = $tokens[$index + $count];
                }

                $value = trim($valueToken[1], "'\"");

                $value = match ($value) {
                    'true' => 1,
                    'false' => 0,
                    default => $value,
                };

                $defaults[trim($previousToken[1], "'\"")] = $value;
            }

            // Check for the closing bracket of the array
            if ($token === ']') {
                $inArray = false;
                break;
            }
        }

        return $defaults;
    }
}
