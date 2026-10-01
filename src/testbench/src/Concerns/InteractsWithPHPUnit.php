<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Collection;
use Hypervel\Testbench\PHPUnit\AttributeParser;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;

/**
 * @internal
 *
 * @property null|Application $app
 */
trait InteractsWithPHPUnit
{
    use InteractsWithTestCase;

    /**
     * The cached test case setUp resolver.
     *
     * @var null|(Closure(Closure):void)
     */
    protected ?Closure $testCaseSetUpCallback = null;

    /**
     * The cached test case tearDown resolver.
     *
     * @var null|(Closure(Closure):void)
     */
    protected ?Closure $testCaseTearDownCallback = null;

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
     * Determine if the object is running as a PHPUnit test case.
     *
     * @api
     */
    public function isRunningTestCase(): bool
    {
        return $this instanceof PHPUnitTestCase || static::usesTestingConcern();
    }

    /**
     * Resolve the PHPUnit test class name.
     *
     * @internal
     *
     * @return null|class-string
     */
    public function resolvePhpUnitTestClassName(): ?string
    {
        $instance = new ReflectionClass($this);

        if (! $this instanceof PHPUnitTestCase || $instance->isAnonymous()) {
            return null;
        }

        return $instance->getName();
    }

    /**
     * Resolve the PHPUnit test method name.
     *
     * @internal
     */
    public function resolvePhpUnitTestMethodName(): ?string
    {
        if (! $this instanceof PHPUnitTestCase) {
            return null;
        }

        return $this->name();
    }

    /**
     * Resolve and cache PHPUnit attributes for current test.
     *
     * @internal
     *
     * @return Collection<class-string, Collection<int, object>>
     */
    protected function resolvePhpUnitAttributes(): Collection
    {
        $className = $this->resolvePhpUnitTestClassName();
        $methodName = $this->resolvePhpUnitTestMethodName();

        if ($className === null) {
            return new Collection;
        }

        return static::resolvePhpUnitAttributesForMethod($className, $methodName);
    }

    /**
     * Resolve attributes for class (and optionally method).
     *
     * @internal
     *
     * @param class-string $className
     * @return Collection<class-string, Collection<int, object>>
     */
    protected static function resolvePhpUnitAttributesForMethod(string $className, ?string $methodName = null): Collection
    {
        if (! isset(static::$cachedTestCaseClassAttributes[$className])) {
            static::$cachedTestCaseClassAttributes[$className] = AttributeParser::forClass($className);
        }

        if ($methodName !== null && ! isset(static::$cachedTestCaseMethodAttributes["{$className}:{$methodName}"])) {
            static::$cachedTestCaseMethodAttributes["{$className}:{$methodName}"] = AttributeParser::forMethod($className, $methodName);
        }

        return (new Collection(array_merge(
            static::$testCaseTestingFeatures,
            static::$cachedTestCaseClassAttributes[$className],
            static::$testCaseMethodTestingFeatures,
            $methodName !== null ? static::$cachedTestCaseMethodAttributes["{$className}:{$methodName}"] : [],
        )))->groupBy('key')
            ->map(static fn (Collection $attributes): Collection => $attributes->pluck('instance'));
    }

    /**
     * Define the setUp environment using callback.
     *
     * @internal
     *
     * @param Closure(Closure):void $setUp
     */
    public function setUpTheEnvironmentUsing(Closure $setUp): void
    {
        $this->testCaseSetUpCallback = $setUp;
    }

    /**
     * Define the tearDown environment using callback.
     *
     * @internal
     *
     * @param Closure(Closure):void $tearDown
     */
    public function tearDownTheEnvironmentUsing(Closure $tearDown): void
    {
        $this->testCaseTearDownCallback = $tearDown;
    }

    /**
     * Cache uses for test case before class runs.
     *
     * @internal
     */
    public static function setUpBeforeClassUsingPHPUnit(): void
    {
        static::cachedUsesForTestCase();
    }

    /**
     * Clear PHPUnit caches after class teardown.
     *
     * @internal
     */
    public static function tearDownAfterClassUsingPHPUnit(): void
    {
        static::$cachedTestCaseUses = [];
        static::$cachedTestCaseClassAttributes = [];
        static::$cachedTestCaseMethodAttributes = [];
    }
}
