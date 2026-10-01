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
use Throwable;

use function Hypervel\Testbench\hypervel_or_fail;

trait InteractsWithTestCase
{
    /**
     * Cached traits used by each test case class.
     *
     * Every class composing this trait shares one static slot with its subclasses.
     *
     * @var array<class-string, array<class-string, class-string>>
     */
    protected static array $cachedTestCaseUses = [];

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
     * Determine if the test case uses the given trait, or the Testing trait by default.
     *
     * @api
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
     * @internal
     *
     * @return array<class-string, class-string>
     */
    public static function cachedUsesForTestCase(): array
    {
        /** @var array<class-string, class-string> $uses */
        $uses = static::$cachedTestCaseUses[static::class]
            ??= class_uses_recursive(static::class);

        return $uses;
    }

    /**
     * Programmatically add a testing feature attribute.
     *
     * @api
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
     *
     * @internal
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
     *
     * @internal
     */
    protected function tearDownTheTestEnvironmentUsingTestCase(): void
    {
        $exception = null;

        if ($this->app !== null) {
            $app = $this->app;

            try {
                $callbacks = $this->resolvePhpUnitAttributes()
                    ->flatten()
                    ->filter(static fn ($instance) => $instance instanceof AfterEach);

                foreach ($callbacks as $callback) {
                    try {
                        $callback->afterEach($app);
                    } catch (Throwable $throwable) {
                        $exception ??= $throwable;
                    }
                }
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }
        }

        static::$testCaseMethodTestingFeatures = [];

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Load test fixtures and execute BeforeAll lifecycle attributes.
     *
     * @internal
     */
    public static function setUpBeforeClassUsingTestCase(): void
    {
        if (static::usesTestingConcern(WithFixtures::class)) {
            /* @phpstan-ignore-next-line */
            static::setupWithFixturesForTestingEnvironment();
        }

        static::resolvePhpUnitAttributesForMethod(static::class)
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof BeforeAll)
            ->each(static fn ($instance) => $instance->beforeAll());
    }

    /**
     * Execute AfterAll lifecycle attributes and clear caches.
     *
     * @internal
     */
    public static function tearDownAfterClassUsingTestCase(): void
    {
        $exception = null;

        try {
            $callbacks = static::resolvePhpUnitAttributesForMethod(static::class)
                ->flatten()
                ->filter(static fn ($instance) => $instance instanceof AfterAll);

            foreach ($callbacks as $callback) {
                try {
                    $callback->afterAll();
                } catch (Throwable $throwable) {
                    $exception ??= $throwable;
                }
            }
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        static::$testCaseTestingFeatures = [];
        static::$testCaseMethodTestingFeatures = [];

        if ($exception !== null) {
            throw $exception;
        }
    }
}
