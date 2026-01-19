<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Concerns;

use Hyperf\Database\Model\Booted;
use Hyperf\Database\Model\TraitInitializers;
use Hypervel\Database\Eloquent\Attributes\Boot;
use Hypervel\Database\Eloquent\Attributes\Initialize;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HasBootableTraitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset model booted state so each test starts fresh
        Booted::$container = [];
        TraitInitializers::$container = [];

        // Reset static state before each test
        BootableTraitsTestModel::$bootCalled = false;
        BootableTraitsTestModel::$conventionalBootCalled = false;
        BootableTraitsTestModel::$initializeCalled = false;
        BootableTraitsTestModel::$conventionalInitializeCalled = false;
        BootableTraitsTestModel::$bootCallCount = 0;
        BootableTraitsTestModel::$initializeCallCount = 0;
    }

    protected function tearDown(): void
    {
        // Reset model booted state
        Booted::$container = [];
        TraitInitializers::$container = [];

        // Reset static state after each test
        BootableTraitsTestModel::$bootCalled = false;
        BootableTraitsTestModel::$conventionalBootCalled = false;
        BootableTraitsTestModel::$initializeCalled = false;
        BootableTraitsTestModel::$conventionalInitializeCalled = false;
        BootableTraitsTestModel::$bootCallCount = 0;
        BootableTraitsTestModel::$initializeCallCount = 0;

        parent::tearDown();
    }

    public function testBootAttributeCallsStaticMethodDuringBoot(): void
    {
        $this->assertFalse(BootableTraitsTestModel::$bootCalled);

        // Creating a model triggers boot
        new BootableTraitsTestModel();

        $this->assertTrue(BootableTraitsTestModel::$bootCalled);
    }

    public function testConventionalBootMethodStillWorks(): void
    {
        $this->assertFalse(BootableTraitsTestModel::$conventionalBootCalled);

        new BootableTraitsTestModel();

        $this->assertTrue(BootableTraitsTestModel::$conventionalBootCalled);
    }

    public function testInitializeAttributeAddsMethodToInitializers(): void
    {
        $this->assertFalse(BootableTraitsTestModel::$initializeCalled);

        // Creating a model triggers initialize
        new BootableTraitsTestModel();

        $this->assertTrue(BootableTraitsTestModel::$initializeCalled);
    }

    public function testConventionalInitializeMethodStillWorks(): void
    {
        $this->assertFalse(BootableTraitsTestModel::$conventionalInitializeCalled);

        new BootableTraitsTestModel();

        $this->assertTrue(BootableTraitsTestModel::$conventionalInitializeCalled);
    }

    public function testBothAttributeAndConventionalMethodsWorkTogether(): void
    {
        $this->assertFalse(BootableTraitsTestModel::$bootCalled);
        $this->assertFalse(BootableTraitsTestModel::$conventionalBootCalled);
        $this->assertFalse(BootableTraitsTestModel::$initializeCalled);
        $this->assertFalse(BootableTraitsTestModel::$conventionalInitializeCalled);

        new BootableTraitsTestModel();

        $this->assertTrue(BootableTraitsTestModel::$bootCalled);
        $this->assertTrue(BootableTraitsTestModel::$conventionalBootCalled);
        $this->assertTrue(BootableTraitsTestModel::$initializeCalled);
        $this->assertTrue(BootableTraitsTestModel::$conventionalInitializeCalled);
    }

    public function testBootMethodIsOnlyCalledOnce(): void
    {
        BootableTraitsTestModel::$bootCallCount = 0;

        new BootableTraitsTestModel();
        new BootableTraitsTestModel();
        new BootableTraitsTestModel();

        // Boot should only be called once regardless of how many instances
        $this->assertSame(1, BootableTraitsTestModel::$bootCallCount);
    }

    public function testInitializeMethodIsCalledForEachInstance(): void
    {
        BootableTraitsTestModel::$initializeCallCount = 0;

        new BootableTraitsTestModel();
        new BootableTraitsTestModel();
        new BootableTraitsTestModel();

        // Initialize should be called for each instance
        $this->assertSame(3, BootableTraitsTestModel::$initializeCallCount);
    }
}

// Test trait with #[Boot] attribute method
trait HasCustomBootMethod
{
    #[Boot]
    public static function customBootMethod(): void
    {
        static::$bootCalled = true;
        ++static::$bootCallCount;
    }
}

// Test trait with conventional boot method
trait HasConventionalBootMethod
{
    public static function bootHasConventionalBootMethod(): void
    {
        static::$conventionalBootCalled = true;
    }
}

// Test trait with #[Initialize] attribute method
trait HasCustomInitializeMethod
{
    #[Initialize]
    public function customInitializeMethod(): void
    {
        static::$initializeCalled = true;
        ++static::$initializeCallCount;
    }
}

// Test trait with conventional initialize method
trait HasConventionalInitializeMethod
{
    public function initializeHasConventionalInitializeMethod(): void
    {
        static::$conventionalInitializeCalled = true;
    }
}

class BootableTraitsTestModel extends Model
{
    use HasCustomBootMethod;
    use HasConventionalBootMethod;
    use HasCustomInitializeMethod;
    use HasConventionalInitializeMethod;

    public static bool $bootCalled = false;

    public static bool $conventionalBootCalled = false;

    public static bool $initializeCalled = false;

    public static bool $conventionalInitializeCalled = false;

    public static int $bootCallCount = 0;

    public static int $initializeCallCount = 0;

    protected ?string $table = 'test_models';
}
