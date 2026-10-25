<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Attributes\SetUp;
use Hypervel\Foundation\Testing\Attributes\TearDown;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

class BootTraitsTest extends TestCase
{
    public function testSetUpAndTearDownTraits(): void
    {
        $testCase = new TestCaseWithTrait('foo');

        $method = new ReflectionMethod($testCase, 'setUpTraits');
        $method->invoke($testCase);

        $this->assertTrue($testCase->setUp);

        $method = new ReflectionMethod($testCase, 'callBeforeApplicationDestroyedCallbacks');
        $method->invoke($testCase);

        $this->assertTrue($testCase->tearDown);
    }

    public function testSetUpAndTearDownWithAttributes(): void
    {
        $testCase = new TestCaseWithAttributeTrait('foo');

        $method = new ReflectionMethod($testCase, 'setUpTraits');
        $method->invoke($testCase);

        $this->assertTrue($testCase->attributeSetUp);

        $method = new ReflectionMethod($testCase, 'callBeforeApplicationDestroyedCallbacks');
        $method->invoke($testCase);

        $this->assertTrue($testCase->attributeTearDown);
    }

    public function testConventionalAndAttributeTraitsWorkTogether(): void
    {
        $testCase = new TestCaseWithBothTraits('foo');

        $method = new ReflectionMethod($testCase, 'setUpTraits');
        $method->invoke($testCase);

        $this->assertTrue($testCase->setUp);
        $this->assertTrue($testCase->attributeSetUp);

        $method = new ReflectionMethod($testCase, 'callBeforeApplicationDestroyedCallbacks');
        $method->invoke($testCase);

        $this->assertTrue($testCase->tearDown);
        $this->assertTrue($testCase->attributeTearDown);
    }

    public function testMissingTruncatedDatabaseInvalidatesStateBeforeEagerRefresh(): void
    {
        $app = new Application;
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'persistent',
                'connections' => [
                    'persistent' => ['driver' => 'sqlite', 'database' => '/unused/database.sqlite'],
                    'secondary' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $testCase = m::mock(TestCaseWithComposedDatabaseResets::class, ['foo'])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $testCase->expects('refreshDatabase')->ordered()->andReturnUsing(function (): void {
            $this->assertFalse(RefreshDatabaseState::$migrated);
            RefreshDatabaseState::$migrated = true;
        });
        $testCase->expects('truncateDatabaseTables')->ordered()->andReturnUsing(function (): void {
            $this->assertTrue(RefreshDatabaseState::$migrated);
        });
        RefreshDatabaseState::$migrated = true;
        RefreshDatabaseState::$inMemoryConnections = [];

        try {
            $testCase->prepare($app);
        } finally {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        }
    }
}

class TestCaseWithComposedDatabaseResets extends FoundationTestCase
{
    use RefreshDatabase;
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary'];

    /**
     * Run Foundation's database setup without booting a Testbench application.
     */
    public function prepare(ApplicationContract $app): void
    {
        $this->app = $app;
        $this->setUpDatabaseTraits(class_uses_recursive(static::class));
    }

    /**
     * Provide the test method required by PHPUnit's lifecycle.
     */
    public function foo(): void
    {
    }
}

class TestCaseWithTrait extends FoundationTestCase
{
    use TestTrait;

    /**
     * Dummy test method required for setUpTraits() to work.
     *
     * PHPUnit TestCase expects the named test method to exist, and
     * AttributeParser reflects on it to check for database attributes.
     */
    public function foo(): void
    {
    }
}

class TestCaseWithAttributeTrait extends FoundationTestCase
{
    use TestTraitWithAttributes;

    public function foo(): void
    {
    }
}

class TestCaseWithBothTraits extends FoundationTestCase
{
    use TestTrait;
    use TestTraitWithAttributes;

    public function foo(): void
    {
    }
}

trait TestTrait
{
    public bool $setUp = false;

    public bool $tearDown = false;

    public function setUpTestTrait()
    {
        $this->setUp = true;
    }

    public function tearDownTestTrait()
    {
        $this->tearDown = true;
    }
}

trait TestTraitWithAttributes
{
    public bool $attributeSetUp = false;

    public bool $attributeTearDown = false;

    #[SetUp]
    public function initializeSearch()
    {
        $this->attributeSetUp = true;
    }

    #[TearDown]
    public function cleanUpSearch()
    {
        $this->attributeTearDown = true;
    }
}
