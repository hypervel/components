<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Attribute;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Contracts\Attributes\AfterAll;
use Hypervel\Testbench\Contracts\Attributes\AfterEach;
use Hypervel\Testbench\Contracts\Attributes\BeforeAll;
use Hypervel\Testbench\Contracts\Attributes\BeforeEach;
use Hypervel\Testbench\Contracts\Attributes\Resolvable;
use Hypervel\Testbench\PHPUnit\AttributeParser;

use function Hypervel\Testbench\hypervel_or_fail;

trait InteractsWithTestCase
{
    /**
     * Cached application bootstrap file path.
     */
    protected static string|bool|null $cacheApplicationBootstrapFile = null;

    /**
     * Cached traits used by test case.
     *
     * @var null|array<class-string, class-string>
     */
    protected static ?array $cachedTestCaseUses = null;

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
     * Check if the test case uses a specific trait.
     *
     * @param null|class-string $trait
     */
    public static function usesTestingConcern(?string $trait = null): bool
    {
        return isset(static::cachedUsesForTestCase()[$trait ?? Testing::class]);
    }

    /**
     * Determine if the test case uses refresh-database testing concerns.
     */
    public static function usesRefreshDatabaseTestingConcern(): bool
    {
        return static::usesTestingConcern(LazilyRefreshDatabase::class)
            || static::usesTestingConcern(RefreshDatabase::class);
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
     * Resolve PHPUnit method attributes for specific method.
     *
     * @param class-string $className
     * @return Collection<class-string, Collection<int, object>>
     */
    abstract protected static function resolvePhpUnitAttributesForMethod(string $className, ?string $methodName = null): Collection;

    /**
     * Execute BeforeEach lifecycle attributes.
     */
    protected function setUpTheTestEnvironmentUsingTestCase(): void
    {
        $app = hypervel_or_fail($this->app);

        $this->resolvePhpUnitAttributes()
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof BeforeEach)
            ->each(static fn ($instance) => $instance->beforeEach($app));
    }

    /**
     * Execute AfterEach lifecycle attributes.
     */
    protected function tearDownTheTestEnvironmentUsingTestCase(): void
    {
        $app = hypervel_or_fail($this->app);

        $this->resolvePhpUnitAttributes()
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof AfterEach)
            ->each(static fn ($instance) => $instance->afterEach($app));

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
        static::$cacheApplicationBootstrapFile = null;
    }
}
