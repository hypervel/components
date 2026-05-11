<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

use Closure;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Routing\Route as BaseRoute;
use Hypervel\Routing\RouteAction;
use Hypervel\Support\Collection;
use Hypervel\Support\Js;
use Hypervel\Support\Str;
use Laravel\SerializableClosure\Support\ReflectionClosure;
use ReflectionClass;
use ReflectionParameter;

class Route
{
    private ?array $parsedRoot = null;

    /**
     * Create a new Wayfinder route wrapper.
     */
    public function __construct(
        private BaseRoute $base,
        private Collection $paramDefaults,
        private ?string $forcedScheme,
        private ?string $forcedRoot
    ) {
    }

    /**
     * Determine whether the route resolves to a controller class.
     */
    public function hasController(): bool
    {
        return $this->base->getControllerClass() !== null;
    }

    /**
     * Return the controller's fully qualified class name as a dot-delimited namespace.
     */
    public function dotNamespace(): string
    {
        return str_replace('\\', '.', Str::chopStart($this->controller(), '\\'));
    }

    /**
     * Determine whether the controller is invokable (single __invoke method).
     */
    public function hasInvokableController(): bool
    {
        return $this->base->getActionName() === $this->base->getActionMethod();
    }

    /**
     * Return the PHP method name on the controller for this route.
     */
    public function method(): string
    {
        return $this->hasInvokableController()
            ? '__invoke'
            : $this->base->getActionMethod();
    }

    /**
     * Return the TypeScript-safe method name for the generated export.
     */
    public function jsMethod(): string
    {
        return $this->finalJsMethod($this->originalJsMethod());
    }

    /**
     * Return the unmodified method name as it appears on the controller.
     */
    public function originalJsMethod(): string
    {
        return $this->hasInvokableController()
            ? Str::afterLast($this->controller(), '\\')
            : $this->base->getActionMethod();
    }

    /**
     * Return the TypeScript-safe method name derived from the route's name.
     */
    public function namedMethod(): string
    {
        return $this->finalJsMethod(Str::afterLast($this->name(), '.'));
    }

    /**
     * Return the controller class with a leading namespace separator.
     */
    public function controller(): string
    {
        return $this->hasInvokableController()
            ? Str::start($this->base->getActionName(), '\\')
            : Str::start($this->base->getControllerClass(), '\\');
    }

    /**
     * Return the Wayfinder Parameter objects describing each route parameter.
     *
     * @return Collection<int, Parameter>
     */
    public function parameters(): Collection
    {
        $optionalParameters = collect($this->base->toSymfonyRoute()->getDefaults());

        $signatureParams = collect($this->base->signatureParameters(UrlRoutable::class));

        return collect($this->base->parameterNames())->map(fn (string $name) => new Parameter(
            $name,
            $optionalParameters->has($name) || $this->paramDefaults->has($name),
            $this->base->bindingFieldFor($name),
            $this->paramDefaults->get($name),
            $signatureParams->first(fn (ReflectionParameter $p) => Str::snake($p->getName()) === Str::snake($name)),
        ));
    }

    /**
     * Return the HTTP verbs this route responds to.
     *
     * @return Collection<int, Verb>
     */
    public function verbs(): Collection
    {
        return collect($this->base->methods())->mapInto(Verb::class);
    }

    /**
     * Build the URI template used in the generated TypeScript output.
     */
    public function uri(): string
    {
        $defaultParams = $this->paramDefaults->mapWithKeys(fn (mixed $value, string $key) => ["{{$key}}" => "{{$key}?}"]);

        $uri = str($this->base->uri)->start('/')->toString();

        if (($basePath = $this->basePath()) !== '') {
            $uri = str($basePath)->finish('/')->append(ltrim($uri, '/'))->toString();
        }

        if (($domain = $this->domain()) !== null) {
            $uri = ($this->scheme() ?? '//') . $domain . $uri;
        }

        $uri = str($uri)
            ->replace($defaultParams->keys()->toArray(), $defaultParams->values()->toArray())
            ->toString();

        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        return Js::from($uri, JSON_UNESCAPED_SLASHES)->toHtml();
    }

    /**
     * Return the URL scheme that should prefix this route's URI (including '://').
     */
    public function scheme(): ?string
    {
        if ($this->base->httpOnly()) {
            return 'http://';
        }

        if ($this->base->httpsOnly()) {
            return 'https://';
        }

        if ($this->forcedRoot) {
            $parts = $this->getParsedRoot();

            if (isset($parts['scheme'])) {
                return $parts['scheme'] . '://';
            }
        }

        return $this->forcedScheme;
    }

    /**
     * Return the route's domain (including any port).
     */
    public function domain(): ?string
    {
        if ($this->base->getDomain()) {
            return $this->base->getDomain();
        }

        if ($this->forcedRoot) {
            $parts = $this->getParsedRoot();

            if (isset($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';

                return $parts['host'] . $port;
            }
        }

        return null;
    }

    /**
     * Return the route's name, normalising namespaced names for the generator.
     */
    public function name(): ?string
    {
        $name = $this->base->getName();

        if (! $name || Str::endsWith($name, '.') || Str::startsWith($name, 'generated::')) {
            return null;
        }

        if (str_contains($name, '::')) {
            return 'namespaced.' . str_replace('::', '.', $name);
        }

        return $name;
    }

    /**
     * Return a project-relative path to the controller (or closure) file.
     */
    public function controllerPath(): string
    {
        $controller = $this->controller();

        if ($controller === '\Closure') {
            $path = $this->relativePath((new ReflectionClosure($this->closure()))->getFileName());

            if (str_contains($path, 'laravel-serializable-closure')) {
                return '[serialized-closure]';
            }

            return $path;
        }

        if (! class_exists($controller)) {
            return '[unknown]';
        }

        return $this->relativePath((new ReflectionClass($controller))->getFileName());
    }

    /**
     * Return the starting line number of the controller method (or closure).
     */
    public function controllerMethodLineNumber(): int
    {
        $controller = $this->controller();

        if ($controller === '\Closure') {
            return (new ReflectionClosure($this->closure()))->getStartLine();
        }

        if (! class_exists($controller)) {
            return 0;
        }

        $reflection = (new ReflectionClass($controller));

        if ($reflection->hasMethod($this->method())) {
            return $reflection->getMethod($this->method())->getStartLine();
        }

        return 0;
    }

    /**
     * Apply the TypeScript safe-method transformation with the 'Method' suffix.
     */
    private function finalJsMethod(string $method): string
    {
        return TypeScript::safeMethod($method, 'Method');
    }

    /**
     * Return the path component of the forced/base URL, prefixed with '/'.
     */
    private function basePath(): string
    {
        $parts = $this->getParsedRoot();

        if (! isset($parts['path'])) {
            return '';
        }

        $path = '/' . trim($parts['path'], '/');

        return $path === '/' ? '' : $path;
    }

    /**
     * Parse and memoise the components of the forced root URL (or app.url fallback).
     */
    private function getParsedRoot(): array
    {
        if ($this->parsedRoot !== null) {
            return $this->parsedRoot;
        }

        $url = $this->forcedRoot ?: config('app.url');

        if (! is_string($url) || $url === '') {
            return $this->parsedRoot = [];
        }

        if (str_starts_with($url, '//')) {
            $url = 'http:' . $url;
        }

        return $this->parsedRoot = parse_url($url) ?: [];
    }

    /**
     * Convert an absolute file path to a path relative to the application base.
     */
    private function relativePath(string $path): string
    {
        return str($path)->replace(base_path(), '')->ltrim(DIRECTORY_SEPARATOR)->replace(DIRECTORY_SEPARATOR, '/')->toString();
    }

    /**
     * Return the closure backing the route's action.
     */
    private function closure(): Closure
    {
        return RouteAction::containsSerializedClosure($this->base->getAction())
            ? unserialize($this->base->getAction('uses'))->getClosure()
            : $this->base->getAction('uses');
    }
}
