<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Closure;
use Hypervel\Support\Collection;
use Hypervel\Testbench\PHPUnit\AttributeParser;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PHPUnit\Metadata\Annotation\Parser\Registry as PHPUnitRegistry;
use ReflectionClass;

/**
 * @internal
 *
 * @property null|\Hypervel\Contracts\Foundation\Application $app
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
     */
    public function isRunningTestCase(): bool
    {
        return $this instanceof PHPUnitTestCase || static::usesTestingConcern();
    }

    /**
     * Resolve the PHPUnit test class name.
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
     * @param class-string $className
     * @return Collection<class-string, Collection<int, object>>
     */
    protected static function resolvePhpUnitAttributesForMethod(string $className, ?string $methodName = null): Collection
    {
        if (! isset(static::$cachedTestCaseClassAttributes[$className])) {
            static::$cachedTestCaseClassAttributes[$className] = rescue(
                static fn () => AttributeParser::forClass($className),
                [],
                false
            );
        }

        if ($methodName !== null && ! isset(static::$cachedTestCaseMethodAttributes["{$className}:{$methodName}"])) {
            static::$cachedTestCaseMethodAttributes["{$className}:{$methodName}"] = rescue(
                static fn () => AttributeParser::forMethod($className, $methodName),
                [],
                false
            );
        }

        return (new Collection(array_merge(
            static::$testCaseTestingFeatures,
            static::$cachedTestCaseClassAttributes[$className],
            static::$testCaseMethodTestingFeatures,
            $methodName !== null ? static::$cachedTestCaseMethodAttributes["{$className}:{$methodName}"] : [],
        )))->groupBy('key')
            ->map(static fn ($attrs) => $attrs->pluck('instance'));
    }

    /**
     * Define the setUp environment using callback.
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
     * @param Closure(Closure):void $tearDown
     */
    public function tearDownTheEnvironmentUsing(Closure $tearDown): void
    {
        $this->testCaseTearDownCallback = $tearDown;
    }

    /**
     * Cache uses for test case before class runs.
     */
    public static function setUpBeforeClassUsingPHPUnit(): void
    {
        static::cachedUsesForTestCase();
    }

    /**
     * Clear PHPUnit caches after class teardown.
     */
    public static function tearDownAfterClassUsingPHPUnit(): void
    {
        static::$cachedTestCaseUses = null;
        static::$cachedTestCaseClassAttributes = [];
        static::$cachedTestCaseMethodAttributes = [];

        if (class_exists(PHPUnitRegistry::class)) {
            (function () {
                $this->classDocBlocks = [];
                $this->methodDocBlocks = [];
            })->call(PHPUnitRegistry::getInstance());
        }
    }
}
