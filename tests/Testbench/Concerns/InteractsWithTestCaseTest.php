<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\AfterAll;
use Hypervel\Testbench\Contracts\Attributes\AfterEach;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class InteractsWithTestCaseTest extends TestCase
{
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

    public function testPestEnvironmentResolversRunBeforeTheLifecycleWrappers(): void
    {
        TestbenchLifecycleTestCaseFixture::$usesPest = true;
        $testCase = new TestbenchLifecycleTestCaseFixture('testPlaceholder');
        $testCase->useApplication($this->app);

        try {
            $testCase->runSetUp();
            $testCase->runTearDown();

            $this->assertSame(1, $testCase->pestSetUpCalls);
            $this->assertSame(1, $testCase->pestTearDownCalls);
        } finally {
            TestbenchLifecycleTestCaseFixture::$usesPest = false;
        }
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
                'bootstrapFile' => null,
            ], TestbenchLifecycleTestCaseFixture::classLifecycleState());
        } finally {
            TestbenchLifecycleTestCaseFixture::forceClearLifecycleState();
        }
    }
}

class TestbenchLifecycleTestCaseFixture extends TestCase
{
    public static bool $usesPest = false;

    public int $foundationSetUpCalls = 0;

    public int $manifestSetupCalls = 0;

    public int $attributeSetUpCalls = 0;

    public int $pestSetUpCalls = 0;

    public int $attributeTearDownCalls = 0;

    public int $foundationTearDownCalls = 0;

    public int $pestTearDownCalls = 0;

    public function testPlaceholder(): void
    {
    }

    public static function usesTestingConcern(?string $trait = null): bool
    {
        if ($trait === \Hypervel\Testbench\Pest\WithPest::class) {
            return static::$usesPest;
        }

        return parent::usesTestingConcern($trait);
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
            'bootstrapFile' => static::$cacheApplicationBootstrapFile,
        ];
    }

    public static function forceClearLifecycleState(): void
    {
        static::$testCaseTestingFeatures = [];
        static::$testCaseMethodTestingFeatures = [];
        static::$cacheApplicationBootstrapFile = null;
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

    protected function setUpTheEnvironmentUsingPest(): void
    {
        ++$this->pestSetUpCalls;
    }

    protected function tearDownTheEnvironmentUsingPest(): void
    {
        ++$this->pestTearDownCalls;
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
