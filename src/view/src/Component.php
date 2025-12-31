<?php

declare(strict_types=1);

namespace Hypervel\View;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Support\Collection;
use Hypervel\Support\Contracts\Arrayable;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\View\Contracts\Factory;
use Hypervel\View\Contracts\View as ViewContract;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

abstract class Component
{
    /**
     * The properties / methods that should not be exposed to the component.
     */
    protected array $except = [];

    /**
     * The component alias name.
     */
    public ?string $componentName = null;

    /**
     * The component attributes.
     */
    public ?ComponentAttributeBag $attributes = null;

    /**
     * The view factory instance, if any.
     */
    protected static ?Factory $factory = null;

    /**
     * The component resolver callback.
     *
     * @var null|(Closure(string, array): static)
     */
    protected static ?Closure $componentsResolver = null;

    /**
     * The cache of blade view names, keyed by contents.
     *
     * @var array<string, string>
     */
    protected static array $bladeViewCache = [];

    /**
     * The cache of public property names, keyed by class.
     */
    protected static array $propertyCache = [];

    /**
     * The cache of public method names, keyed by class.
     */
    protected static array $methodCache = [];

    /**
     * The cache of constructor parameters, keyed by class.
     *
     * @var array<class-string, array<int, string>>
     */
    protected static array $constructorParametersCache = [];

    /**
     * The cache of ignored parameter names.
     */
    protected static array $ignoredParameterNames = [];

    /**
     * Get the view / view contents that represent the component.
     */
    abstract public function render(): ViewContract|Htmlable|Closure|string;

    /**
     * Resolve the component instance with the given data.
     */
    public static function resolve(array $data): static
    {
        if (static::$componentsResolver) {
            return call_user_func(static::$componentsResolver, static::class, $data);
        }

        $parameters = static::extractConstructorParameters();

        $dataKeys = array_keys($data);

        if (empty(array_diff($parameters, $dataKeys))) {
            return new static(...array_intersect_key($data, array_flip($parameters)));
        }

        return Container::getInstance()->make(static::class, $data);
    }

    /**
     * Extract the constructor parameters for the component.
     */
    protected static function extractConstructorParameters(): array
    {
        if (! isset(static::$constructorParametersCache[static::class])) {
            $class = new ReflectionClass(static::class);

            $constructor = $class->getConstructor();

            static::$constructorParametersCache[static::class] = $constructor
                ? (new Collection($constructor->getParameters()))->map(fn ($p) => $p->getName())->all()
                : [];
        }

        return static::$constructorParametersCache[static::class];
    }

    /**
     * Resolve the Blade view or view file that should be used when rendering the component.
     */
    public function resolveView(): ViewContract|Htmlable|Closure|string
    {
        $view = $this->render();

        if ($view instanceof ViewContract) {
            return $view;
        }

        if ($view instanceof Htmlable) {
            return $view;
        }

        $resolver = function ($view) {
            if ($view instanceof ViewContract) {
                return $view;
            }

            return $this->extractBladeViewFromString($view);
        };

        return $view instanceof Closure ? function (array $data = []) use ($view, $resolver) {
            return $resolver($view($data));
        }
        : $resolver($view);
    }

    /**
     * Create a Blade view with the raw component string content.
     */
    protected function extractBladeViewFromString(string $contents): string
    {
        $key = sprintf('%s::%s', static::class, $contents);

        if (isset(static::$bladeViewCache[$key])) {
            return static::$bladeViewCache[$key];
        }

        if ($this->factory()->exists($contents)) {
            return static::$bladeViewCache[$key] = $contents;
        }

        return static::$bladeViewCache[$key] = $this->createBladeViewFromString($this->factory(), $contents);
    }

    /**
     * Create a Blade view with the raw component string content.
     */
    protected function createBladeViewFromString(Factory $factory, string $contents): string
    {
        $factory->addNamespace(
            '__components',
            $directory = Container::getInstance()['config']->get('view.compiled')
        );

        if (! is_file($viewFile = $directory . '/' . hash('xxh128', $contents) . '.blade.php')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($viewFile, $contents);
        }

        return '__components::' . basename($viewFile, '.blade.php');
    }

    /**
     * Get the data that should be supplied to the view.
     */
    public function data(): array
    {
        $this->attributes = $this->attributes ?: $this->newAttributeBag();

        return array_merge($this->extractPublicProperties(), $this->extractPublicMethods());
    }

    /**
     * Extract the public properties for the component.
     */
    protected function extractPublicProperties(): array
    {
        $class = get_class($this);

        if (! isset(static::$propertyCache[$class])) {
            $reflection = new ReflectionClass($this);

            static::$propertyCache[$class] = (new Collection($reflection->getProperties(ReflectionProperty::IS_PUBLIC)))
                ->reject(fn (ReflectionProperty $property) => $property->isStatic())
                ->reject(fn (ReflectionProperty $property) => $this->shouldIgnore($property->getName()))
                ->map(fn (ReflectionProperty $property) => $property->getName())
                ->all();
        }

        $values = [];

        foreach (static::$propertyCache[$class] as $property) {
            $values[$property] = $this->{$property};
        }

        return $values;
    }

    /**
     * Extract the public methods for the component.
     */
    protected function extractPublicMethods(): array
    {
        $class = get_class($this);

        if (! isset(static::$methodCache[$class])) {
            $reflection = new ReflectionClass($this);

            static::$methodCache[$class] = (new Collection($reflection->getMethods(ReflectionMethod::IS_PUBLIC)))
                ->reject(fn (ReflectionMethod $method) => $this->shouldIgnore($method->getName()))
                ->map(fn (ReflectionMethod $method) => $method->getName());
        }

        $values = [];

        foreach (static::$methodCache[$class] as $method) {
            $values[$method] = $this->createVariableFromMethod(new ReflectionMethod($this, $method));
        }

        return $values;
    }

    /**
     * Create a callable variable from the given method.
     */
    protected function createVariableFromMethod(ReflectionMethod $method): mixed
    {
        return $method->getNumberOfParameters() === 0
                        ? $this->createInvokableVariable($method->getName())
                        : Closure::fromCallable([$this, $method->getName()]);
    }

    /**
     * Create an invokable, toStringable variable for the given component method.
     */
    protected function createInvokableVariable(string $method): InvokableComponentVariable
    {
        return new InvokableComponentVariable(function () use ($method) {
            return $this->{$method}();
        });
    }

    /**
     * Determine if the given property / method should be ignored.
     */
    protected function shouldIgnore(string $name): bool
    {
        return str_starts_with($name, '__')
               || in_array($name, $this->ignoredMethods());
    }

    /**
     * Get the methods that should be ignored.
     */
    protected function ignoredMethods(): array
    {
        return array_merge([
            'data',
            'render',
            'resolve',
            'resolveView',
            'shouldRender',
            'view',
            'withName',
            'withAttributes',
            'flushCache',
            'forgetFactory',
            'forgetComponentsResolver',
            'resolveComponentsUsing',
        ], $this->except);
    }

    /**
     * Set the component alias name.
     */
    public function withName(string $name): static
    {
        $this->componentName = $name;

        return $this;
    }

    /**
     * Set the extra attributes that the component should make available.
     */
    public function withAttributes(array $attributes): static
    {
        $this->attributes = $this->attributes ?: $this->newAttributeBag();

        $this->attributes->setAttributes($attributes);

        return $this;
    }

    /**
     * Get a new attribute bag instance.
     */
    protected function newAttributeBag(array $attributes = []): ComponentAttributeBag
    {
        return new ComponentAttributeBag($attributes);
    }

    /**
     * Determine if the component should be rendered.
     */
    public function shouldRender(): bool
    {
        return true;
    }

    /**
     * Get the evaluated view contents for the given view.
     */
    public function view(?string $view, Arrayable|array $data = [], array $mergeData = []): ViewContract
    {
        return $this->factory()->make($view, $data, $mergeData);
    }

    /**
     * Get the view factory instance.
     */
    protected function factory(): Factory
    {
        if (is_null(static::$factory)) {
            static::$factory = Container::getInstance()->make('view');
        }

        return static::$factory;
    }

    /**
     * Get the cached set of anonymous component constructor parameter names to exclude.
     */
    public static function ignoredParameterNames(): array
    {
        if (! isset(static::$ignoredParameterNames[static::class])) {
            $constructor = (new ReflectionClass(
                static::class
            ))->getConstructor();

            if (! $constructor) {
                return static::$ignoredParameterNames[static::class] = [];
            }

            static::$ignoredParameterNames[static::class] = (new Collection($constructor->getParameters()))
                ->map(fn ($p) => $p->getName())
                ->all();
        }

        return static::$ignoredParameterNames[static::class];
    }

    /**
     * Flush the component's cached state.
     */
    public static function flushCache(): void
    {
        static::$bladeViewCache = [];
        static::$constructorParametersCache = [];
        static::$methodCache = [];
        static::$propertyCache = [];
    }

    /**
     * Forget the component's factory instance.
     */
    public static function forgetFactory(): void
    {
        static::$factory = null;
    }

    /**
     * Forget the component's resolver callback.
     *
     * @internal
     */
    public static function forgetComponentsResolver(): void
    {
        static::$componentsResolver = null;
    }

    /**
     * Set the callback that should be used to resolve components within views.
     *
     * @param Closure(string $component, array $data): static $resolver
     *
     * @internal
     */
    public static function resolveComponentsUsing(Closure $resolver): void
    {
        static::$componentsResolver = $resolver;
    }
}
