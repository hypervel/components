<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

use BackedEnum;
use Closure;
use Hypervel\Console\Command;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\Route as BaseRoute;
use Hypervel\Routing\Router;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\URL;
use Hypervel\Support\Js;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Hypervel\View\Factory;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Prompts\info;

#[AsCommand(name: 'wayfinder:generate')]
class GenerateCommand extends Command
{
    private const array GENERATED_IDENTIFIERS = [
        'queryParams',
        'applyUrlDefaults',
        'validateParameters',
        'formatRouteParameter',
        'RouteQueryOptions',
        'RouteDefinition',
        'RouteFormDefinition',
    ];

    protected ?string $signature = 'wayfinder:generate {--path=} {--skip-actions} {--skip-routes} {--with-form}';

    private ?string $forcedScheme = null;

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
        if ($this->option('path') === '') {
            throw new InvalidArgumentException('The --path option may not be empty.');
        }

        $this->view->replaceNamespace('wayfinder', __DIR__ . '/../resources');
        $this->view->addExtension('blade.ts', 'blade');

        $this->forcedScheme = $this->url->getForcedScheme();

        $globalUrlDefaults = collect(URL::getDefaultParameters())->filter(
            fn (mixed $value): bool => $value instanceof UrlRoutable
                || $value instanceof BackedEnum
                || is_scalar($value),
        );

        $routes = collect($this->router->getRoutes())->map(function (BaseRoute $route) use ($globalUrlDefaults) {
            $defaults = collect($this->router->gatherRouteMiddleware($route))->map(function (mixed $middleware): array {
                if ($middleware instanceof Closure) {
                    return [];
                }

                $this->urlDefaults[$middleware] ??= $this->getDefaultsForMiddleware($middleware);

                return $this->urlDefaults[$middleware];
            })->flatMap(fn (array $r) => $r);

            return new Route($route, $globalUrlDefaults->merge($defaults), $this->forcedScheme);
        });

        $this->writeWayfinderHelperFile();

        if (! $this->option('skip-actions')) {
            $controllers = $routes->filter(fn (Route $route) => $route->hasController())->groupBy(fn (Route $route) => $route->dotNamespace());
            $controllerScopes = $this->barrelScopes($controllers->keys(), 'module', false);
            $controllerNames = $this->allocateBarrelNames($controllerScopes);

            $this->writeBarrelFiles($controllerScopes, $controllerNames);
            $controllers->each($this->writeControllerFile(...));

            $this->pruneStaleFiles($this->base(), $this->writeContent());

            info('[Wayfinder] Generated actions in ' . $this->base());
        }

        $this->pathDirectory = 'routes';

        if (! $this->option('skip-routes')) {
            $named = $routes->filter(fn (Route $route) => $route->name() !== null)->groupBy(fn (Route $route) => $route->name());
            $namedScopes = $this->barrelScopes(
                $named->keys(),
                'method',
                $this->option('with-form') === true,
            );
            $namedNames = $this->allocateBarrelNames($namedScopes);

            $named->each(function (Collection $routes, int|string $namespace) use ($namedNames) {
                $namespace = (string) $namespace;
                $scope = $this->parentNamespace($namespace);

                $this->writeNamedFile(
                    $routes,
                    $namespace,
                    $namedNames[$scope]['method:' . $namespace],
                );
            });
            $this->writeBarrelFiles($namedScopes, $namedNames);

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

        if (! in_array($content, $this->content[$path], true)) {
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
            $this->files->replace($path, $content);
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
                if (! $this->files->delete($path) && $this->files->exists($path)) {
                    throw new RuntimeException("Unable to delete stale generated file [{$path}].");
                }
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
        $scope = $this->parentNamespace($namespace);

        if ($scope !== '') {
            $barrelPath = join_paths(...[$this->base(), ...explode('.', $scope), 'index.ts']);

            if (strcasecmp($path, $barrelPath) === 0) {
                throw new InvalidArgumentException(sprintf(
                    'Controller [%s] cannot generate module [%s] because it conflicts with barrel [%s] on case-insensitive filesystems.',
                    $routes->first()->controller(),
                    $path,
                    $barrelPath,
                ));
            }
        }

        $this->appendCommonImports($routes, $path, $namespace);

        /** @var Collection<string, Collection<int, Route>> $methodRoutes */
        $methodRoutes = $routes->groupBy(fn (Route $route) => $route->method());
        $invokableRoutes = $methodRoutes->get('__invoke');
        $defaultExport = $invokableRoutes instanceof Collection
            ? TypeScript::safeMethod($invokableRoutes->first()->originalJsMethod(), 'Method')
            : TypeScript::safeMethod(last(explode('.', $namespace)), 'Method');
        $allocatableRoutes = $invokableRoutes instanceof Collection
            ? $methodRoutes->except('__invoke')
            : $methodRoutes;
        $withForm = $this->option('with-form') === true;
        $reserved = [...self::GENERATED_IDENTIFIERS, $defaultExport];

        if ($invokableRoutes instanceof Collection && $withForm) {
            $reserved[] = $defaultExport . 'Form';
        }

        $allocatedNames = $this->allocateNames(
            $allocatableRoutes->map(fn (Collection $routes): array => [
                'name' => $routes->first()->originalJsMethod(),
                'createsForm' => $withForm,
            ]),
            $reserved,
            'Method',
        );
        $allocatedMethods = [];

        foreach ($allocatableRoutes->keys()->values() as $index => $action) {
            $allocatedMethods[(string) $action] = $allocatedNames->get($index);
        }

        if ($invokableRoutes instanceof Collection) {
            $allocatedMethods['__invoke'] = $defaultExport;
        }

        $methodRoutes->each(function (Collection $routes, int|string $action) use ($allocatedMethods, $path) {
            $method = $allocatedMethods[(string) $action];

            if ($routes->count() === 1) {
                $this->writeControllerMethodExport($routes->first(), $path, $method);

                return;
            }

            $this->writeMultiRouteControllerMethodExport($routes, $path, $method);
        });

        if ($invokableRoutes instanceof Collection) {
            $methodProps = $allocatableRoutes->map(function (Collection $routes, int|string $action) use ($allocatedMethods, $defaultExport): string {
                $property = Js::from($routes->first()->originalJsMethod())->toHtml();

                return "{$defaultExport}[{$property}] = {$allocatedMethods[(string) $action]}";
            })->implode(PHP_EOL);
        } else {
            $methodProps = $allocatableRoutes->map(function (Collection $routes, int|string $action) use ($allocatedMethods): string {
                $property = TypeScript::quoteIfNeeded($routes->first()->originalJsMethod());
                $method = $allocatedMethods[(string) $action];

                return $property === $method ? $method : "{$property}: {$method}";
            })->implode(', ');
            $methodProps = "const {$defaultExport} = { {$methodProps} }";
        }

        $this->appendContent($path, <<<JAVASCRIPT
        {$methodProps}

        export default {$defaultExport}
        JAVASCRIPT);
    }

    /**
     * Allocate deterministic TypeScript identifiers in registration order.
     *
     * @param Collection<array-key, array{name: string, createsForm: bool}> $candidates
     * @param string[] $reserved
     * @return Collection<int, string>
     */
    private function allocateNames(
        Collection $candidates,
        array $reserved,
        string $suffix,
    ): Collection {
        $candidates = $candidates->values();
        $natural = $candidates
            ->map(fn (array $candidate): string => TypeScript::safeMethod($candidate['name'], $suffix));
        $counts = $natural->countBy();
        $naturalSet = array_fill_keys($natural->all(), true);
        $formShadowConflicts = [];
        $used = array_fill_keys($reserved, true);
        $allocated = [];

        foreach ($natural as $index => $candidate) {
            if ($candidates[$index]['createsForm'] && isset($naturalSet[$candidate . 'Form'])) {
                $formShadowConflicts[$candidate] = true;
                $formShadowConflicts[$candidate . 'Form'] = true;
            }
        }

        $available = function (string $candidate, bool $createsForm) use (&$used): bool {
            return ! isset($used[$candidate])
                && (! $createsForm || ! isset($used[$candidate . 'Form']));
        };
        $claim = function (string $candidate, bool $createsForm) use (&$used): void {
            $used[$candidate] = true;

            if ($createsForm) {
                $used[$candidate . 'Form'] = true;
            }
        };

        foreach ($natural as $index => $candidate) {
            $createsForm = $candidates[$index]['createsForm'];

            if ($counts[$candidate] === 1
                && $available($candidate, $createsForm)
                && ! isset($formShadowConflicts[$candidate])) {
                $allocated[$index] = $candidate;
                $claim($candidate, $createsForm);
            }
        }

        foreach ($natural as $index => $naturalCandidate) {
            if (isset($allocated[$index])) {
                continue;
            }

            $candidate = $naturalCandidate;
            $createsForm = $candidates[$index]['createsForm'];
            $suffixIndex = 2;

            while (! $available($candidate, $createsForm)) {
                $candidate = $naturalCandidate . $suffixIndex++;
            }

            $allocated[$index] = $candidate;
            $claim($candidate, $createsForm);
        }

        ksort($allocated);

        return collect($allocated);
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
    private function writeMultiRouteControllerMethodExport(Collection $routes, string $path, string $method): void
    {
        $isInvokable = $routes->first()->hasInvokableController();
        $renderedRoutes = $routes->groupBy(fn (Route $route) => $route->uri())
            ->map(function (Collection $sameUriRoutes, string $uri) use ($method): array {
                $route = $sameUriRoutes->first();
                $descriptor = $this->parameterDescriptor($route);

                foreach ($sameUriRoutes->skip(1) as $candidate) {
                    $candidateDescriptor = $this->parameterDescriptor($candidate);

                    if ($candidateDescriptor !== $descriptor) {
                        throw new InvalidArgumentException(sprintf(
                            'Routes for [%s::%s] and URI [%s] resolve different parameter metadata: %s and %s.',
                            $route->controller(),
                            $route->originalJsMethod(),
                            $uri,
                            json_encode($descriptor, JSON_THROW_ON_ERROR),
                            json_encode($candidateDescriptor, JSON_THROW_ON_ERROR),
                        ));
                    }
                }

                return [
                    'method' => $route->originalJsMethod(),
                    'tempMethod' => $method . hash('xxh128', $uri),
                    'parameters' => $route->parameters(),
                    'verbs' => $sameUriRoutes
                        ->flatMap(fn (Route $route) => $route->verbs())
                        ->unique(fn (Verb $verb) => $verb->actual)
                        ->values(),
                    'uri' => $uri,
                ];
            })->values();

        $this->appendContent($path, $this->view->make('wayfinder::multi-method', [
            'method' => $method,
            'original_method' => $routes->first()->originalJsMethod(),
            'path' => $routes->first()->controllerPath(),
            'line' => $routes->first()->controllerMethodLineNumber(),
            'controller' => $routes->first()->controller(),
            'isInvokable' => $isInvokable,
            'shouldExport' => ! $isInvokable,
            'withForm' => $this->option('with-form') === true,
            ...$this->safeParamNames($method),
            'routes' => $renderedRoutes,
        ])->render());
    }

    /**
     * Return the resolved parameter metadata that must agree for one URI entry.
     *
     * @return array<int, array{name: string, optional: bool, routeOptional: bool, key: ?string, default: null|bool|float|int|string, types: string}>
     */
    private function parameterDescriptor(Route $route): array
    {
        return $route->parameters()->map(fn (Parameter $parameter): array => [
            'name' => $parameter->name,
            'optional' => $parameter->optional,
            'routeOptional' => $parameter->routeOptional,
            'key' => $parameter->key,
            'default' => $parameter->default,
            'types' => $parameter->types,
        ])->all();
    }

    /**
     * Render the single-method template for a controller route.
     */
    private function writeControllerMethodExport(Route $route, string $path, string $method): void
    {
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
            'withForm' => $this->option('with-form') === true,
            ...$this->safeParamNames($method),
        ])->render());
    }

    /**
     * Write the TypeScript file for a named route group.
     *
     * @param Collection<int, Route> $routes
     */
    private function writeNamedFile(Collection $routes, string $namespace, string $method): void
    {
        $parts = explode('.', $namespace);
        array_pop($parts);
        $parts[] = 'index';

        $path = join_paths($this->base(), ...$parts) . '.ts';

        $this->appendCommonImports($routes, $path, $namespace);

        $routes->each(fn (Route $route) => $this->writeNamedMethodExport($route, $path, $method));
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
            $imports[] = 'formatRouteParameter';
        }

        if ($routes->contains(fn (Route $route) => $route->parameters()->contains(fn (Parameter $parameter) => $parameter->routeOptional))) {
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
    private function writeNamedMethodExport(Route $route, string $path, string $method): void
    {
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
            'withForm' => $this->option('with-form') === true,
            ...$this->safeParamNames($method),
        ])->render());
    }

    /**
     * Build the declaration scopes represented by flat dotted names.
     *
     * @param Collection<array-key, int|string> $names
     * @return array<string, array{candidates: array<int, array{id: string, name: string, createsForm: bool, kind: string}>, candidateIds: array<string, true>, segments: array<string, array{external: string, leaf: ?string, namespace: ?string}>}>
     */
    private function barrelScopes(Collection $names, string $leafKind, bool $leafCreatesForm): array
    {
        $scopes = [];

        foreach ($names as $name) {
            $name = (string) $name;
            $parts = explode('.', $name);
            $lastIndex = count($parts) - 1;

            for ($index = 1; $index < $lastIndex; ++$index) {
                $scope = implode('.', array_slice($parts, 0, $index));
                $external = $parts[$index];
                $namespace = implode('.', array_slice($parts, 0, $index + 1));

                $this->addBarrelCandidate(
                    $scopes,
                    $scope,
                    $external,
                    'namespace',
                    'namespace:' . $namespace,
                    false,
                );
            }

            $scope = implode('.', array_slice($parts, 0, $lastIndex));
            $this->addBarrelCandidate(
                $scopes,
                $scope,
                $parts[$lastIndex],
                $leafKind,
                $leafKind . ':' . $name,
                $leafCreatesForm,
            );
        }

        return $scopes;
    }

    /**
     * Add one leaf or namespace declaration to a barrel scope.
     *
     * @param array<string, array{candidates: array<int, array{id: string, name: string, createsForm: bool, kind: string}>, candidateIds: array<string, true>, segments: array<string, array{external: string, leaf: ?string, namespace: ?string}>}> $scopes
     */
    private function addBarrelCandidate(
        array &$scopes,
        string $scope,
        string $external,
        string $kind,
        string $id,
        bool $createsForm,
    ): void {
        $scopes[$scope] ??= [
            'candidates' => [],
            'candidateIds' => [],
            'segments' => [],
        ];

        if (isset($scopes[$scope]['candidateIds'][$id])) {
            return;
        }

        $scopes[$scope]['candidateIds'][$id] = true;
        $scopes[$scope]['candidates'][] = [
            'id' => $id,
            'name' => $external,
            'createsForm' => $createsForm,
            'kind' => $kind,
        ];

        $segmentKey = 'segment:' . $external;
        $scopes[$scope]['segments'][$segmentKey] ??= [
            'external' => $external,
            'leaf' => null,
            'namespace' => null,
        ];
        $slot = $kind === 'namespace' ? 'namespace' : 'leaf';
        $scopes[$scope]['segments'][$segmentKey][$slot] = $id;
    }

    /**
     * Allocate every declaration name in each barrel scope.
     *
     * @param array<string, array{candidates: array<int, array{id: string, name: string, createsForm: bool, kind: string}>, candidateIds: array<string, true>, segments: array<string, array{external: string, leaf: ?string, namespace: ?string}>}> $scopes
     * @return array<string, array<string, string>>
     */
    private function allocateBarrelNames(array $scopes): array
    {
        $names = [];

        foreach ($scopes as $scope => $definition) {
            $candidates = collect($definition['candidates']);

            if ($scope !== '') {
                $candidates->push([
                    'id' => 'default:' . $scope,
                    'name' => Str::afterLast($scope, '.'),
                    'createsForm' => false,
                    'kind' => 'default',
                ]);
            }

            $allocated = $this->allocateNames(
                $candidates->map(fn (array $candidate): array => [
                    'name' => $candidate['name'],
                    'createsForm' => $candidate['createsForm'],
                ]),
                self::GENERATED_IDENTIFIERS,
                'Method',
            );

            foreach ($candidates as $index => $candidate) {
                $names[$scope][$candidate['id']] = $allocated[$index];
            }
        }

        return $names;
    }

    /**
     * Write barrel index files from their complete declaration scopes.
     *
     * @param array<string, array{candidates: array<int, array{id: string, name: string, createsForm: bool, kind: string}>, candidateIds: array<string, true>, segments: array<string, array{external: string, leaf: ?string, namespace: ?string}>}> $scopes
     * @param array<string, array<string, string>> $names
     */
    private function writeBarrelFiles(array $scopes, array $names): void
    {
        foreach ($scopes as $scope => $definition) {
            if ($scope === '') {
                continue;
            }

            $indexPath = join_paths(...[$this->base(), ...explode('.', $scope), 'index.ts']);
            $imports = [];

            foreach ($definition['candidates'] as $candidate) {
                if (! in_array($candidate['kind'], ['module', 'namespace'], true)) {
                    continue;
                }

                $segment = $definition['segments']['segment:' . $candidate['name']];
                $explicitIndex = $candidate['kind'] === 'namespace'
                    && ($segment['leaf'] !== null || $candidate['name'] === 'index');
                $specifier = './' . $candidate['name'] . ($explicitIndex ? '/index' : '');
                $imports[] = 'import ' . $names[$scope][$candidate['id']]
                    . ' from ' . json_encode($specifier, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            if ($imports !== []) {
                $this->prependContent($indexPath, implode(PHP_EOL, $imports));
            }

            $segments = collect($definition['segments'])->map(function (array $segment): array {
                $segment['normalized'] = str($segment['external'])
                    ->whenContains('-', fn (Stringable $name) => $name->camel())
                    ->toString();

                return $segment;
            });
            $normalizedCounts = $segments->pluck('normalized')->countBy();

            $properties = $segments->map(function (array $segment) use ($names, $normalizedCounts, $scope): string {
                $leaf = $segment['leaf'] === null ? null : $names[$scope][$segment['leaf']];
                $namespace = $segment['namespace'] === null ? null : $names[$scope][$segment['namespace']];
                $value = $leaf !== null && $namespace !== null
                    ? "Object.assign({$leaf}, {$namespace})"
                    : ($leaf ?? $namespace);
                $external = $normalizedCounts->get($segment['normalized']) > 1
                    ? $segment['external']
                    : $segment['normalized'];
                $property = TypeScript::quoteIfNeeded($external);

                return str_repeat(' ', 4) . ($property === $value ? $value : "{$property}: {$value}");
            })->implode(',' . PHP_EOL);
            $defaultExport = $names[$scope]['default:' . $scope];

            $this->appendContent($indexPath, <<<JAVASCRIPT


                    const {$defaultExport} = {
                    {$properties},
                    }

                    export default {$defaultExport}
                    JAVASCRIPT);
        }
    }

    /**
     * Return the dotted parent namespace of a generated route name.
     */
    private function parentNamespace(string $namespace): string
    {
        $position = strrpos($namespace, '.');

        return $position === false ? '' : substr($namespace, 0, $position);
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
     * @return array<string, null|bool|float|int|string>
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

        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $lines = file($fileName);
        $methodContents = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        if (! str_contains($methodContents, 'URL::defaults')) {
            return [];
        }

        $methodContents = str($methodContents)->after('{')->beforeLast('}')->trim()->toString();

        return $this->extractUrlDefaults($methodContents);
    }

    /**
     * Tokenise the middleware method body and extract every URL::defaults() array.
     *
     * @return array<string, null|bool|float|int|string>
     */
    private function extractUrlDefaults(string $methodContents): array
    {
        $tokens = token_get_all('<?php ' . $methodContents);
        $defaults = [];

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            if (! $this->startsUrlDefaultsCall($tokens, $index)) {
                continue;
            }

            [$entries, $index] = $this->readDefaultsArray($tokens, $index);
            $defaults = [...$defaults, ...$entries];
        }

        return $defaults;
    }

    /**
     * Determine whether the token begins a URL::defaults() array call.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function startsUrlDefaultsCall(array $tokens, int $index): bool
    {
        return $this->defaultsArrayStart($tokens, $index) !== null;
    }

    /**
     * Read one URL::defaults() array and return its entries and closing token index.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array{0: array<string, null|bool|float|int|string>, 1: int}
     */
    private function readDefaultsArray(array $tokens, int $index): array
    {
        $openIndex = $this->defaultsArrayStart($tokens, $index);

        if ($openIndex === null) {
            return [[], $index];
        }

        $open = $this->tokenText($tokens[$openIndex]);
        $close = $open === '[' ? ']' : ')';
        $closeIndex = $this->matchingDelimiter($tokens, $openIndex, $open, $close);

        if ($closeIndex === null) {
            return [[], $index];
        }

        $entries = [];
        $segments = $this->splitTopLevelTokens(array_slice(
            $tokens,
            $openIndex + 1,
            $closeIndex - $openIndex - 1,
        ));

        foreach ($segments as $segment) {
            $entry = $this->parseDefaultEntry($segment);

            if ($entry !== null) {
                [$key, $value] = $entry;
                $entries[$key] = $value;
            }
        }

        return [$entries, $closeIndex];
    }

    /**
     * Locate the array opener for a URL::defaults() call.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function defaultsArrayStart(array $tokens, int $index): ?int
    {
        $token = $tokens[$index] ?? null;

        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'URL') {
            return null;
        }

        $doubleColon = $this->nextMeaningfulTokenIndex($tokens, $index);
        $method = $doubleColon === null ? null : $this->nextMeaningfulTokenIndex($tokens, $doubleColon);
        $callOpen = $method === null ? null : $this->nextMeaningfulTokenIndex($tokens, $method);
        $array = $callOpen === null ? null : $this->nextMeaningfulTokenIndex($tokens, $callOpen);

        if ($doubleColon === null
            || $this->tokenText($tokens[$doubleColon]) !== '::'
            || $method === null
            || ! is_array($tokens[$method])
            || $tokens[$method][0] !== T_STRING
            || $tokens[$method][1] !== 'defaults'
            || $callOpen === null
            || $this->tokenText($tokens[$callOpen]) !== '('
            || $array === null) {
            return null;
        }

        if ($this->tokenText($tokens[$array]) === '[') {
            return $array;
        }

        if (! is_array($tokens[$array]) || $tokens[$array][0] !== T_ARRAY) {
            return null;
        }

        $arrayOpen = $this->nextMeaningfulTokenIndex($tokens, $array);

        return $arrayOpen !== null && $this->tokenText($tokens[$arrayOpen]) === '('
            ? $arrayOpen
            : null;
    }

    /**
     * Return the next token that is not whitespace or a comment.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function nextMeaningfulTokenIndex(array $tokens, int $index): ?int
    {
        for ($next = $index + 1, $count = count($tokens); $next < $count; ++$next) {
            $token = $tokens[$next];

            if (! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $next;
            }
        }

        return null;
    }

    /**
     * Find the matching closing delimiter.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function matchingDelimiter(
        array $tokens,
        int $openIndex,
        string $open,
        string $close,
    ): ?int {
        $depth = 0;

        for ($index = $openIndex, $count = count($tokens); $index < $count; ++$index) {
            $text = $this->tokenText($tokens[$index]);

            if ($text === $open) {
                ++$depth;
            } elseif ($text === $close && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Split tokens at top-level commas.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array<int, array<int, array{int, string, int}|string>>
     */
    private function splitTopLevelTokens(array $tokens): array
    {
        $segments = [];
        $segment = [];
        $depth = 0;

        foreach ($tokens as $token) {
            $text = $this->tokenText($token);

            if ($text === ',' && $depth === 0) {
                $segments[] = $segment;
                $segment = [];

                continue;
            }

            if (in_array($text, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                --$depth;
            }

            $segment[] = $token;
        }

        if ($segment !== []) {
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Parse one top-level URL default entry.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @return null|array{0: string, 1: null|bool|float|int|string}
     */
    private function parseDefaultEntry(array $tokens): ?array
    {
        $tokens = $this->meaningfulTokens($tokens);
        $depth = 0;
        $arrow = null;

        foreach ($tokens as $index => $token) {
            $text = $this->tokenText($token);

            if ($text === '=>' && $depth === 0) {
                $arrow = $index;

                break;
            }

            if (in_array($text, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                --$depth;
            }
        }

        if ($arrow === null) {
            return null;
        }

        $keyTokens = $this->meaningfulTokens(array_slice($tokens, 0, $arrow));

        if (count($keyTokens) !== 1
            || ! is_array($keyTokens[0])
            || $keyTokens[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        [$include, $value] = $this->parseDefaultValue(array_slice($tokens, $arrow + 1));

        return $include ? [$this->decodeQuotedString($keyTokens[0][1]), $value] : null;
    }

    /**
     * Parse a supported literal or recognize a dynamic default expression.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array{0: bool, 1: null|bool|float|int|string}
     */
    private function parseDefaultValue(array $tokens): array
    {
        $tokens = $this->meaningfulTokens($tokens);

        if ($tokens === []) {
            return [false, null];
        }

        $first = $tokens[0];

        if ($this->tokenText($first) === '[' || (is_array($first) && $first[0] === T_ARRAY)) {
            return [false, null];
        }

        if (count($tokens) === 1 && is_array($first)) {
            return match ($first[0]) {
                T_CONSTANT_ENCAPSED_STRING => [true, $this->decodeQuotedString($first[1])],
                T_LNUMBER => [true, intval(str_replace('_', '', $first[1]), 0)],
                T_DNUMBER => [true, (float) str_replace('_', '', $first[1])],
                T_STRING => match (strtolower($first[1])) {
                    'true' => [true, true],
                    'false' => [true, false],
                    'null' => [false, null],
                    default => [true, null],
                },
                default => [true, null],
            };
        }

        if (count($tokens) === 2
            && in_array($this->tokenText($first), ['+', '-'], true)
            && is_array($tokens[1])
            && in_array($tokens[1][0], [T_LNUMBER, T_DNUMBER], true)) {
            $number = $tokens[1][0] === T_LNUMBER
                ? intval(str_replace('_', '', $tokens[1][1]), 0)
                : (float) str_replace('_', '', $tokens[1][1]);

            return [true, $this->tokenText($first) === '-' ? -$number : $number];
        }

        return [true, null];
    }

    /**
     * Remove whitespace and comments from a token sequence.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array<int, array{int, string, int}|string>
     */
    private function meaningfulTokens(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /**
     * Decode a constant quoted PHP string token.
     */
    private function decodeQuotedString(string $literal): string
    {
        $quote = $literal[0];
        $value = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $value)
            : stripcslashes($value);
    }

    /**
     * Return a token's source text.
     *
     * @param array{int, string, int}|string $token
     */
    private function tokenText(array|string $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }
}
