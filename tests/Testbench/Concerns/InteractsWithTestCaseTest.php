<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Attributes\Define;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\HandlesAttributes;
use Hypervel\Testbench\Concerns\InteractsWithTestCase;
use Hypervel\Testbench\Contracts\Attributes\AfterAll;
use Hypervel\Testbench\Contracts\Attributes\AfterEach;
use Hypervel\Testbench\PHPUnit\AttributeParser;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Testbench\Fixtures\BootstrapFileApplication;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

#[WithConfig('testing.class_level', 'class_value')]
class InteractsWithTestCaseTest extends TestCase
{
    public function testUsesTestingConcernReturnsTrueForUsedTrait(): void
    {
        $this->assertTrue(static::usesTestingConcern(HandlesAttributes::class));
        $this->assertTrue(static::usesTestingConcern(InteractsWithTestCase::class));
    }

    public function testUsesTestingConcernReturnsFalseForUnusedTrait(): void
    {
        $this->assertFalse(static::usesTestingConcern('NonExistentTrait'));
    }

    public function testCachedUsesForTestCaseReturnsTraits(): void
    {
        $uses = static::cachedUsesForTestCase();

        $this->assertArrayHasKey(HandlesAttributes::class, $uses);
        $this->assertArrayHasKey(InteractsWithTestCase::class, $uses);
    }

    public function testCachedUsesAreScopedToEachTestCaseClass(): void
    {
        $this->assertArrayNotHasKey(
            DatabaseMigrations::class,
            PlainTestCaseFixture::cachedUsesForTestCase(),
        );
        $this->assertArrayHasKey(
            DatabaseMigrations::class,
            MigratingTestCaseFixture::cachedUsesForTestCase(),
        );
    }

    public function testNestedFixtureReadsItsOwnTestingConcerns(): void
    {
        $this->assertFalse(static::usesTestingConcern(DatabaseMigrations::class));

        $testCase = new MigratingTestCaseFixture('testPlaceholder');

        $this->assertTrue($testCase->usesDatabaseMigrations());
        $this->assertFalse(static::usesTestingConcern(DatabaseMigrations::class));
    }

    public function testNestedFixtureScopesBootstrapFileSelectionToItsBasePath(): void
    {
        $testCase = new BootstrapFileTestCaseFixture('testPlaceholder');
        $app = $testCase->resolveApplicationForTest();

        try {
            $this->assertInstanceOf(BootstrapFileApplication::class, $app);
            $this->assertSame(
                realpath(dirname(__DIR__) . '/Fixtures/ApplicationWithBootstrap/bootstrap/app.php'),
                $app->bootstrapFile,
            );
        } finally {
            $app->flush();
        }
    }

    public function testResolvePhpUnitAttributesReturnsCollection(): void
    {
        $this->assertInstanceOf(Collection::class, $this->resolvePhpUnitAttributes());
    }

    #[WithConfig('testing.method_level', 'method_value')]
    public function testResolvePhpUnitAttributesMergesClassAndMethodAttributes(): void
    {
        $withConfigInstances = $this->resolvePhpUnitAttributes()->get(WithConfig::class);

        $this->assertCount(2, $withConfigInstances);
    }

    public function testClassLevelAttributeIsApplied(): void
    {
        $this->assertSame('class_value', config('testing.class_level'));
    }

    public function testUsesTestingFeatureAddsAttribute(): void
    {
        static::usesTestingFeature(
            new WithConfig('testing.programmatic', 'added'),
            Attribute::TARGET_METHOD,
        );

        $this->assertTrue($this->resolvePhpUnitAttributes()->has(WithConfig::class));
    }

    public function testDefineMetaAttributeIsResolvedByAttributeParser(): void
    {
        $attributes = AttributeParser::forMethod(
            DefineMetaAttributeTestCaseFixture::class,
            'testWithDefineAttribute',
        );

        $this->assertCount(1, $attributes);
        $this->assertSame(DefineEnvironment::class, $attributes[0]['key']);
        $this->assertInstanceOf(DefineEnvironment::class, $attributes[0]['instance']);
        $this->assertSame('setupDefineEnv', $attributes[0]['instance']->method);
    }

    #[Define('env', 'setupDefineEnvForExecution')]
    public function testDefineMetaAttributeIsExecutedThroughLifecycle(): void
    {
        $this->assertSame(
            'define_env_executed',
            config('testing.define_meta_attribute'),
        );
    }

    protected function setupDefineEnvForExecution(ApplicationContract $app): void
    {
        $app->make('config')->set('testing.define_meta_attribute', 'define_env_executed');
    }

    public function testResolvePhpUnitAttributesReturnsCollectionOfCollections(): void
    {
        $attributes = $this->resolvePhpUnitAttributes();

        $this->assertInstanceOf(Collection::class, $attributes);

        $attributes->each(function ($value, $key): void {
            $this->assertInstanceOf(
                Collection::class,
                $value,
                "Value for key {$key} should be a Collection, not " . gettype($value),
            );
        });
    }

    #[DataProvider('setupWrapperProvider')]
    public function testSetupWrapperRunsTheParentPhaseAtMostOnce(
        string $shape,
        int $expectedCalls,
        bool $expectsException,
    ): void {
        $exception = new RuntimeException('setup wrapper failed');
        $testCase = new TestbenchLifecycleTestCaseFixture('testPlaceholder');

        $testCase->setUpTheEnvironmentUsing(match ($shape) {
            'invoke' => static function (Closure $parent): void {
                $parent();
            },
            'return' => static function (Closure $parent): void {
            },
            'throw-before' => static function (Closure $parent) use ($exception): never {
                throw $exception;
            },
            'invoke-then-throw' => static function (Closure $parent) use ($exception): never {
                $parent();

                throw $exception;
            },
        });

        $caught = null;

        try {
            $testCase->runSetUp();
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($expectsException ? $exception : null, $caught);
        $this->assertSame($expectedCalls, $testCase->foundationSetUpCalls);
        $this->assertSame($expectedCalls, $testCase->manifestSetupCalls);
        $this->assertSame($expectedCalls, $testCase->attributeSetUpCalls);
    }

    public static function setupWrapperProvider(): array
    {
        return [
            'invokes parent' => ['invoke', 1, false],
            'returns without parent' => ['return', 1, false],
            'throws before parent' => ['throw-before', 0, true],
            'invokes parent then throws' => ['invoke-then-throw', 1, true],
        ];
    }

    #[DataProvider('teardownWrapperProvider')]
    public function testTeardownWrapperAlwaysRunsTheParentPhaseExactlyOnce(
        string $shape,
        bool $expectsException,
    ): void {
        $exception = new RuntimeException('teardown wrapper failed');
        $testCase = new TestbenchLifecycleTestCaseFixture('testPlaceholder');
        $testCase->useApplication($this->app);
        $testCase->setUpTheEnvironmentUsing(static function (Closure $parent): void {
        });
        $testCase->tearDownTheEnvironmentUsing(match ($shape) {
            'invoke' => static function (Closure $parent): void {
                $parent();
            },
            'return' => static function (Closure $parent): void {
            },
            'throw-before' => static function (Closure $parent) use ($exception): never {
                throw $exception;
            },
            'invoke-then-throw' => static function (Closure $parent) use ($exception): never {
                $parent();

                throw $exception;
            },
        });

        $caught = null;

        try {
            $testCase->runTearDown();
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($expectsException ? $exception : null, $caught);
        $this->assertSame(1, $testCase->attributeTearDownCalls);
        $this->assertSame(1, $testCase->foundationTearDownCalls);
        $this->assertFalse($testCase->hasEnvironmentCallbacks());
    }

    public static function teardownWrapperProvider(): array
    {
        return [
            'invokes parent' => ['invoke', false],
            'returns without parent' => ['return', false],
            'throws before parent' => ['throw-before', true],
            'invokes parent then throws' => ['invoke-then-throw', true],
        ];
    }

    public function testEveryAfterEachCallbackRunsAndMethodFeaturesAreCleared(): void
    {
        LifecycleAttributeFixture::$calls = [];
        $exception = new RuntimeException('first after-each callback failed');
        $testCase = new TestbenchLifecycleTestCaseFixture('testPlaceholder');
        $testCase->useApplication($this->app);

        try {
            TestbenchLifecycleTestCaseFixture::usesTestingFeature(
                new LifecycleAttributeFixture('first', $exception),
                Attribute::TARGET_METHOD,
            );
            TestbenchLifecycleTestCaseFixture::usesTestingFeature(
                new LifecycleAttributeFixture('second'),
                Attribute::TARGET_METHOD,
            );

            try {
                $testCase->runAfterEachAttributes();
                $this->fail('Expected the first AfterEach failure to be rethrown.');
            } catch (RuntimeException $throwable) {
                $this->assertSame($exception, $throwable);
            }

            $this->assertSame(['afterEach:first', 'afterEach:second'], LifecycleAttributeFixture::$calls);
            $this->assertSame([], TestbenchLifecycleTestCaseFixture::methodTestingFeatures());
        } finally {
            TestbenchLifecycleTestCaseFixture::forceClearLifecycleState();
        }
    }

    public function testAfterEachCallbacksAreSkippedWithoutAnApplicationAndMethodFeaturesAreCleared(): void
    {
        LifecycleAttributeFixture::$calls = [];
        $testCase = new TestbenchLifecycleTestCaseFixture('testPlaceholder');

        try {
            TestbenchLifecycleTestCaseFixture::usesTestingFeature(
                new LifecycleAttributeFixture('application-bound'),
                Attribute::TARGET_METHOD,
            );

            $testCase->runAfterEachAttributes();

            $this->assertSame([], LifecycleAttributeFixture::$calls);
            $this->assertSame([], TestbenchLifecycleTestCaseFixture::methodTestingFeatures());
        } finally {
            TestbenchLifecycleTestCaseFixture::forceClearLifecycleState();
        }
    }

    public function testEveryAfterAllCallbackRunsAndClassStateIsCleared(): void
    {
        LifecycleAttributeFixture::$calls = [];
        $exception = new RuntimeException('first after-all callback failed');

        try {
            TestbenchLifecycleTestCaseFixture::usesTestingFeature(
                new LifecycleAttributeFixture('first', $exception),
                Attribute::TARGET_CLASS,
            );
            TestbenchLifecycleTestCaseFixture::usesTestingFeature(
                new LifecycleAttributeFixture('second'),
                Attribute::TARGET_CLASS,
            );

            try {
                TestbenchLifecycleTestCaseFixture::runAfterAllAttributes();
                $this->fail('Expected the first AfterAll failure to be rethrown.');
            } catch (RuntimeException $throwable) {
                $this->assertSame($exception, $throwable);
            }

            $this->assertSame(['afterAll:first', 'afterAll:second'], LifecycleAttributeFixture::$calls);
            $this->assertSame([
                'classFeatures' => [],
                'methodFeatures' => [],
            ], TestbenchLifecycleTestCaseFixture::classLifecycleState());
        } finally {
            TestbenchLifecycleTestCaseFixture::forceClearLifecycleState();
        }
    }
}

class PlainTestCaseFixture extends TestCase
{
}

class MigratingTestCaseFixture extends TestCase
{
    use DatabaseMigrations;

    public function testPlaceholder(): void
    {
    }

    public function usesDatabaseMigrations(): bool
    {
        return static::usesTestingConcern(DatabaseMigrations::class);
    }
}

class BootstrapFileTestCaseFixture extends TestCase
{
    public function testPlaceholder(): void
    {
    }

    /**
     * Resolve the application without booting it.
     */
    public function resolveApplicationForTest(): ApplicationContract
    {
        return $this->resolveApplication();
    }

    #[Override]
    protected function getApplicationBasePath(): string
    {
        return dirname(__DIR__) . '/Fixtures/ApplicationWithBootstrap';
    }
}

class TestbenchLifecycleTestCaseFixture extends TestCase
{
    public int $foundationSetUpCalls = 0;

    public int $manifestSetupCalls = 0;

    public int $attributeSetUpCalls = 0;

    public int $attributeTearDownCalls = 0;

    public int $foundationTearDownCalls = 0;

    public function testPlaceholder(): void
    {
    }

    public function runSetUp(): void
    {
        $this->setUp();
    }

    public function runTearDown(): void
    {
        $this->tearDown();
    }

    public function useApplication(ApplicationContract $app): void
    {
        $this->app = $app;
    }

    public function runAfterEachAttributes(): void
    {
        $this->tearDownTheTestEnvironmentUsingTestCase();
    }

    public static function runAfterAllAttributes(): void
    {
        static::tearDownAfterClassUsingTestCase();
    }

    public function hasEnvironmentCallbacks(): bool
    {
        return $this->testCaseSetUpCallback !== null || $this->testCaseTearDownCallback !== null;
    }

    public static function methodTestingFeatures(): array
    {
        return static::$testCaseMethodTestingFeatures;
    }

    public static function classLifecycleState(): array
    {
        return [
            'classFeatures' => static::$testCaseTestingFeatures,
            'methodFeatures' => static::$testCaseMethodTestingFeatures,
        ];
    }

    public static function forceClearLifecycleState(): void
    {
        static::$testCaseTestingFeatures = [];
        static::$testCaseMethodTestingFeatures = [];
    }

    protected function setUpTheTestEnvironment(): void
    {
        ++$this->foundationSetUpCalls;
    }

    protected function preservePackageManifestCache(): void
    {
        ++$this->manifestSetupCalls;
    }

    protected function setUpTheTestEnvironmentUsingTestCase(): void
    {
        ++$this->attributeSetUpCalls;
    }

    protected function tearDownTheTestEnvironmentUsingTestCase(): void
    {
        ++$this->attributeTearDownCalls;

        parent::tearDownTheTestEnvironmentUsingTestCase();
    }

    protected function tearDownTheTestEnvironment(): void
    {
        ++$this->foundationTearDownCalls;
    }

    protected function tearDownTheTestEnvironmentUsingMockery(): void
    {
    }
}

class LifecycleAttributeFixture implements AfterAll, AfterEach
{
    public static array $calls = [];

    public function __construct(
        protected string $name,
        protected ?Throwable $exception = null,
    ) {
    }

    public function afterEach(ApplicationContract $app): void
    {
        static::$calls[] = "afterEach:{$this->name}";

        if ($this->exception !== null) {
            throw $this->exception;
        }
    }

    public function afterAll(): void
    {
        static::$calls[] = "afterAll:{$this->name}";

        if ($this->exception !== null) {
            throw $this->exception;
        }
    }
}

class DefineMetaAttributeTestCaseFixture extends TestCase
{
    #[Define('env', 'setupDefineEnv')]
    public function testWithDefineAttribute(): void
    {
    }

    protected function setupDefineEnv(ApplicationContract $app): void
    {
    }
}
