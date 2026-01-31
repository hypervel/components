<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Attribute;
use Closure;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Contracts\Attributes\Actionable;
use Hypervel\Testbench\Contracts\Attributes\AfterAll;
use Hypervel\Testbench\Contracts\Attributes\AfterEach;
use Hypervel\Testbench\Contracts\Attributes\BeforeAll;
use Hypervel\Testbench\Contracts\Attributes\BeforeEach;
use Hypervel\Testbench\Contracts\Attributes\Invokable;
use Hypervel\Testbench\Contracts\Attributes\Resolvable;
use Hypervel\Testbench\PHPUnit\AttributeParser;

/**
 * Provides test case lifecycle and attribute caching functionality.
 *
 * @property null|\Hypervel\Contracts\Foundation\Application $app
 */
trait InteractsWithTestCase
{
    /**
     * Cached class attributes by class name.
     *
     * @var array<string, array<int, array{key: class-string, instance: object}>>
     */
    protected static array $cachedTestCaseClassAttributes = [];

    /**
     * Cached method attributes by "class:method" key.
     *
     * @var array<string, array<int, array{key: class-string, instance: object}>>
     */
    protected static array $cachedTestCaseMethodAttributes = [];

    /**
     * Programmatically added class-level testing features.
     *
     * @var array<int, array{key: class-string, instance: object}>
     */
    protected static array $testCaseTestingFeatures = [];

    /**
     * Programmatically added method-level testing features.
     *
     * @var array<int, array{key: class-string, instance: object}>
     */
    protected static array $testCaseMethodTestingFeatures = [];

    /**
     * Cached traits used by test case.
     *
     * @var null|array<class-string, class-string>
     */
    protected static ?array $cachedTestCaseUses = null;

    /**
     * Check if the test case uses a specific trait.
     *
     * @param class-string $trait
     */
    public static function usesTestingConcern(string $trait): bool
    {
        return isset(static::cachedUsesForTestCase()[$trait]);
    }

    /**
     * Cache and return traits used by test case.
     *
     * @return array<class-string, class-string>
     */
    public static function cachedUsesForTestCase(): array
    {
        if (static::$cachedTestCaseUses === null) {
            /** @var array<class-string, class-string> $uses */
            $uses = array_flip(class_uses_recursive(static::class));
            static::$cachedTestCaseUses = $uses;
        }

        return static::$cachedTestCaseUses;
    }

    /**
     * Programmatically add a testing feature attribute.
     */
    public static function usesTestingFeature(object $attribute, int $flag = Attribute::TARGET_CLASS): void
    {
        if (! AttributeParser::validAttribute($attribute)) {
            return;
        }

        $attribute = $attribute instanceof Resolvable ? $attribute->resolve() : $attribute;

        if ($attribute === null) {
            return;
        }

        if ($flag & Attribute::TARGET_CLASS) {
            static::$testCaseTestingFeatures[] = [
                'key' => $attribute::class,
                'instance' => $attribute,
            ];
        } elseif ($flag & Attribute::TARGET_METHOD) {
            static::$testCaseMethodTestingFeatures[] = [
                'key' => $attribute::class,
                'instance' => $attribute,
            ];
        }
    }

    /**
     * Resolve and cache PHPUnit attributes for current test.
     *
     * @return \Hypervel\Support\Collection<class-string, \Hypervel\Support\Collection<int, object>>
     */
    protected function resolvePhpUnitAttributes(): Collection
    {
        $className = static::class;
        $methodName = $this->name();

        // Cache class attributes
        if (! isset(static::$cachedTestCaseClassAttributes[$className])) {
            static::$cachedTestCaseClassAttributes[$className] = AttributeParser::forClass($className);
        }

        // Cache method attributes
        $cacheKey = "{$className}:{$methodName}";
        if (! isset(static::$cachedTestCaseMethodAttributes[$cacheKey])) {
            static::$cachedTestCaseMethodAttributes[$cacheKey] = AttributeParser::forMethod($className, $methodName);
        }

        // Merge all sources and group by attribute class
        return (new Collection(array_merge(
            static::$testCaseTestingFeatures,
            static::$cachedTestCaseClassAttributes[$className],
            static::$testCaseMethodTestingFeatures,
            static::$cachedTestCaseMethodAttributes[$cacheKey],
        )))->groupBy('key')
            ->map(static fn ($attrs) => $attrs->pluck('instance'));
    }

    /**
     * Resolve attributes for class (and optionally method) - used by static lifecycle methods.
     *
     * @param class-string $className
     * @return \Hypervel\Support\Collection<class-string, \Hypervel\Support\Collection<int, object>>
     */
    protected static function resolvePhpUnitAttributesForMethod(string $className, ?string $methodName = null): Collection
    {
        $attributes = array_merge(
            static::$testCaseTestingFeatures,
            AttributeParser::forClass($className),
        );

        if ($methodName !== null) {
            $attributes = array_merge(
                $attributes,
                static::$testCaseMethodTestingFeatures,
                AttributeParser::forMethod($className, $methodName),
            );
        }

        return (new Collection($attributes))
            ->groupBy('key')
            ->map(static fn ($attrs) => $attrs->pluck('instance'));
    }

    /**
     * Execute setup lifecycle attributes (Invokable, Actionable, BeforeEach).
     */
    protected function setUpTheTestEnvironmentUsingTestCase(): void
    {
        $attributes = $this->resolvePhpUnitAttributes()->flatten();

        // Execute Invokable attributes (like WithConfig)
        $attributes
            ->filter(static fn ($instance) => $instance instanceof Invokable)
            ->each(fn ($instance) => $instance($this->app));

        // Execute Actionable attributes (like DefineRoute, DefineDatabase)
        // DefineEnvironment is excluded - it runs earlier in defineEnvironment()
        // before providers boot, so database config can be set before connections pool.
        // Some attributes (like DefineDatabase with defer: true) return a Closure
        // that must be executed to complete the setup
        $attributes
            ->filter(static fn ($instance) => $instance instanceof Actionable && ! $instance instanceof DefineEnvironment)
            ->each(function ($instance): void {
                $result = $instance->handle(
                    $this->app,
                    fn ($method, $parameters) => $this->{$method}(...$parameters)
                );

                if ($result instanceof Closure) {
                    $result();
                }
            });

        // Execute BeforeEach attributes
        $attributes
            ->filter(static fn ($instance) => $instance instanceof BeforeEach)
            ->each(fn ($instance) => $instance->beforeEach($this->app));
    }

    /**
     * Execute AfterEach lifecycle attributes.
     */
    protected function tearDownTheTestEnvironmentUsingTestCase(): void
    {
        $this->resolvePhpUnitAttributes()
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof AfterEach)
            ->each(fn ($instance) => $instance->afterEach($this->app));

        static::$testCaseMethodTestingFeatures = [];
    }

    /**
     * Execute BeforeAll lifecycle attributes.
     */
    public static function setUpBeforeClassUsingTestCase(): void
    {
        static::resolvePhpUnitAttributesForMethod(static::class)
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof BeforeAll)
            ->each(static fn ($instance) => $instance->beforeAll());
    }

    /**
     * Execute AfterAll lifecycle attributes and clear caches.
     */
    public static function tearDownAfterClassUsingTestCase(): void
    {
        static::resolvePhpUnitAttributesForMethod(static::class)
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof AfterAll)
            ->each(static fn ($instance) => $instance->afterAll());

        static::$testCaseTestingFeatures = [];
        static::$cachedTestCaseClassAttributes = [];
        static::$cachedTestCaseMethodAttributes = [];
        static::$cachedTestCaseUses = null;
    }
}
