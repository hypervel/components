<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\Contracts\TestCase as TestCaseContract;
use Hypervel\Testbench\Foundation\Env;
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
        $phpUnitException = new RuntimeException('PHPUnit teardown failed');

        ClassTeardownTestCaseFixture::resetLifecycle(
            $testCaseException,
            $phpUnitException,
        );

        try {
            ClassTeardownTestCaseFixture::tearDownAfterClass();
            $this->fail('Expected class teardown to rethrow the first failure.');
        } catch (Throwable $throwable) {
            $this->assertSame($testCaseException, $throwable);
        }

        $this->assertSame(
            ['test-case', 'phpunit'],
            ClassTeardownTestCaseFixture::$lifecycleCalls,
        );
    }

    #[Test]
    public function itRestoresExactApplicationEnvironmentStateAfterBootstrapFails(): void
    {
        $serverEnvironment = $this->snapshotEnvironmentValue($_SERVER, 'APP_ENV');
        $environmentEnvironment = $this->snapshotEnvironmentValue($_ENV, 'APP_ENV');
        $processEnvironment = getenv('APP_ENV');
        $serverPackageTester = $this->snapshotEnvironmentValue($_SERVER, 'TESTBENCH_PACKAGE_TESTER');
        $environmentPackageTester = $this->snapshotEnvironmentValue($_ENV, 'TESTBENCH_PACKAGE_TESTER');
        $processPackageTester = getenv('TESTBENCH_PACKAGE_TESTER');
        $testCase = new FailingEnvironmentTestCaseFixture('testPlaceholder');

        try {
            unset($_SERVER['TESTBENCH_PACKAGE_TESTER'], $_ENV['TESTBENCH_PACKAGE_TESTER']);
            putenv('TESTBENCH_PACKAGE_TESTER');
            $_SERVER['APP_ENV'] = 'server-environment';
            $_ENV['APP_ENV'] = 'environment-environment';
            putenv('APP_ENV=process-environment');
            Env::flushRepository();

            try {
                $testCase->createFailingApplication();
                $this->fail('Expected environment definition to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('environment definition failed', $exception->getMessage());
            }

            $this->assertArrayNotHasKey('APP_ENV', $_SERVER);
            $this->assertArrayNotHasKey('APP_ENV', $_ENV);
            $this->assertFalse(getenv('APP_ENV'));

            $testCase->destroyApplication();

            $this->assertSame('server-environment', $_SERVER['APP_ENV']);
            $this->assertSame('environment-environment', $_ENV['APP_ENV']);
            $this->assertSame('process-environment', getenv('APP_ENV'));
        } finally {
            $this->restoreEnvironmentValue($_SERVER, 'APP_ENV', $serverEnvironment);
            $this->restoreEnvironmentValue($_ENV, 'APP_ENV', $environmentEnvironment);
            $this->restoreProcessEnvironmentValue('APP_ENV', $processEnvironment);
            $this->restoreEnvironmentValue($_SERVER, 'TESTBENCH_PACKAGE_TESTER', $serverPackageTester);
            $this->restoreEnvironmentValue($_ENV, 'TESTBENCH_PACKAGE_TESTER', $environmentPackageTester);
            $this->restoreProcessEnvironmentValue('TESTBENCH_PACKAGE_TESTER', $processPackageTester);
            Env::flushRepository();
        }
    }

    #[Test]
    public function itKeepsWithEnvActiveAcrossRequestsUntilTestTeardown(): void
    {
        $key = 'TESTBENCH_REQUEST_ENV';
        $serverValue = $this->snapshotEnvironmentValue($_SERVER, $key);
        $environmentValue = $this->snapshotEnvironmentValue($_ENV, $key);
        $processValue = getenv($key);
        $testCase = new WithEnvRequestLifecycleTestCaseFixture('testPlaceholder');

        try {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
            Env::flushRepository();

            $testCase->createTestApplication();

            $this->assertSame('active', $testCase->requestEnvironment());
            $this->assertSame('active', $testCase->requestEnvironment());
            $this->assertSame('active', Env::get($key));

            $testCase->destroyApplication();

            $this->assertNull(Env::get($key));
        } finally {
            $this->restoreEnvironmentValue($_SERVER, $key, $serverValue);
            $this->restoreEnvironmentValue($_ENV, $key, $environmentValue);
            $this->restoreProcessEnvironmentValue($key, $processValue);
            Env::flushRepository();
        }
    }

    /**
     * Snapshot an environment-array value and its presence.
     *
     * @param array<string, mixed> $environment
     * @return array{bool, mixed}
     */
    private function snapshotEnvironmentValue(array $environment, string $key): array
    {
        return [array_key_exists($key, $environment), $environment[$key] ?? null];
    }

    /**
     * Restore an environment-array value and its presence.
     *
     * @param array<string, mixed> $environment
     * @param array{bool, mixed} $snapshot
     */
    private function restoreEnvironmentValue(array &$environment, string $key, array $snapshot): void
    {
        if ($snapshot[0]) {
            $environment[$key] = $snapshot[1];

            return;
        }

        unset($environment[$key]);
    }

    /**
     * Restore a process environment value.
     */
    private function restoreProcessEnvironmentValue(string $key, string|false $value): void
    {
        $value === false ? putenv($key) : putenv("{$key}={$value}");
    }
}

class FailingEnvironmentTestCaseFixture extends \Hypervel\Testbench\TestCase
{
    protected bool $loadEnvironmentVariables = false;

    public function testPlaceholder(): void
    {
    }

    public function createFailingApplication(): void
    {
        $this->app = $this->createApplication();
    }

    public function destroyApplication(): void
    {
        $this->tearDownTheTestEnvironment();
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        throw new RuntimeException('environment definition failed');
    }
}

class WithEnvRequestLifecycleTestCaseFixture extends \Hypervel\Testbench\TestCase
{
    #[WithEnv('TESTBENCH_REQUEST_ENV', 'active')]
    public function testPlaceholder(): void
    {
    }

    public function createTestApplication(): void
    {
        $this->app = $this->createApplication();

        $this->app->make(Router::class)->get(
            '/testbench-request-environment',
            static fn (): string => (string) Env::get('TESTBENCH_REQUEST_ENV'),
        );
    }

    public function requestEnvironment(): string
    {
        $content = null;

        $this->runInCoroutine(function () use (&$content): void {
            $content = $this->get('/testbench-request-environment')->getContent();
        });

        return (string) $content;
    }

    public function destroyApplication(): void
    {
        $this->tearDownTheTestEnvironment();
    }
}

class ClassTeardownTestCaseFixture extends \Hypervel\Testbench\TestCase
{
    public static array $lifecycleCalls = [];

    protected static ?Throwable $testCaseException = null;

    protected static ?Throwable $phpUnitException = null;

    public static function resetLifecycle(
        ?Throwable $testCaseException,
        ?Throwable $phpUnitException,
    ): void {
        static::$lifecycleCalls = [];
        static::$testCaseException = $testCaseException;
        static::$phpUnitException = $phpUnitException;
    }

    public static function tearDownAfterClassUsingTestCase(): void
    {
        static::$lifecycleCalls[] = 'test-case';

        if (static::$testCaseException !== null) {
            throw static::$testCaseException;
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
