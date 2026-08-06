<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Contracts\TestCase as TestCaseContract;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Pest\WithPest;
use Hypervel\Testbench\PHPUnit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

use function Hypervel\Testbench\container;

class TestCaseTest extends TestCase
{
    #[Test]
    public function itCanCreateTheTestcase(): void
    {
        // Use a real dummy test method on the anonymous class so PHPUnit's
        // metadata parser can resolve the stored method name while
        // createApplication() loads environment variables.
        $testbench = new class('testDummy') extends \Hypervel\Testbench\TestCase {
            public function testDummy(): void
            {
            }
        };

        $app = $testbench->createApplication();

        $this->assertInstanceOf(Application::class, $app);
        $this->assertEquals('UTC', date_default_timezone_get());
        $this->assertEquals('testing', $app['env']);
        $this->assertSame('testing', $app->environment());
        $this->assertTrue($app->runningUnitTests());
        $this->assertInstanceOf(ConfigRepository::class, $app['config']);

        $this->assertInstanceOf(TestCaseContract::class, $testbench);
        $this->assertTrue($testbench->isRunningTestCase());
        $this->assertFalse($testbench->isRunningTestCaseUsingPest());

        $app->terminate();
    }

    #[Test]
    public function itCanCreateAContainer(): void
    {
        $container = container();

        $app = $container->createApplication();

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';

        $this->assertInstanceOf(Application::class, $app);
        $this->assertEquals('UTC', date_default_timezone_get());
        $this->assertEquals($environment, $app['env']);
        $this->assertSame($environment, $app->environment());
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $app->runningUnitTests());
        $this->assertInstanceOf(ConfigRepository::class, $app['config']);

        $this->assertFalse($container->isRunningTestCase());
        $this->assertFalse($container->isRunningTestCaseUsingPest());

        $app->terminate();
    }

    #[Test]
    public function itAttemptsEveryClassTeardownPhaseAndPreservesTheEarliestFailure(): void
    {
        $testCaseException = new RuntimeException('test case teardown failed');
        $pestException = new RuntimeException('Pest teardown failed');
        $phpUnitException = new RuntimeException('PHPUnit teardown failed');

        ClassTeardownTestCaseFixture::resetLifecycle(
            $testCaseException,
            $pestException,
            $phpUnitException,
        );

        try {
            ClassTeardownTestCaseFixture::tearDownAfterClass();
            $this->fail('Expected class teardown to rethrow the first failure.');
        } catch (Throwable $throwable) {
            $this->assertSame($testCaseException, $throwable);
        }

        $this->assertSame(
            ['test-case', 'pest', 'phpunit'],
            ClassTeardownTestCaseFixture::$lifecycleCalls,
        );
    }
}

class ClassTeardownTestCaseFixture extends \Hypervel\Testbench\TestCase
{
    public static array $lifecycleCalls = [];

    protected static ?Throwable $testCaseException = null;

    protected static ?Throwable $pestException = null;

    protected static ?Throwable $phpUnitException = null;

    public static function resetLifecycle(
        ?Throwable $testCaseException,
        ?Throwable $pestException,
        ?Throwable $phpUnitException,
    ): void {
        static::$lifecycleCalls = [];
        static::$testCaseException = $testCaseException;
        static::$pestException = $pestException;
        static::$phpUnitException = $phpUnitException;
    }

    public static function usesTestingConcern(?string $trait = null): bool
    {
        if ($trait === WithPest::class) {
            return true;
        }

        return parent::usesTestingConcern($trait);
    }

    public static function tearDownAfterClassUsingTestCase(): void
    {
        static::$lifecycleCalls[] = 'test-case';

        if (static::$testCaseException !== null) {
            throw static::$testCaseException;
        }
    }

    public static function tearDownAfterClassUsingPest(): void
    {
        static::$lifecycleCalls[] = 'pest';

        if (static::$pestException !== null) {
            throw static::$pestException;
        }
    }

    public static function tearDownAfterClassUsingPHPUnit(): void
    {
        static::$lifecycleCalls[] = 'phpunit';

        if (static::$phpUnitException !== null) {
            throw static::$phpUnitException;
        }
    }
}
