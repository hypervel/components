<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Coroutine as HypervelCoroutine;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Timer as SwooleTimer;

class UnitTestTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testUnitTestAttributeSkipsApplicationBoot(): void
    {
        $testCase = new UnitTestFoundationTestCase('unitMethod');

        $testCase->runSetUp();

        $this->assertSame(0, $testCase->applicationCreationCount);
        $this->assertFalse($testCase->shouldBootFramework());
    }

    public function testUnannotatedTestShouldBootFramework(): void
    {
        $testCase = new UnitTestFoundationTestCase('frameworkMethod');

        $this->assertTrue($testCase->shouldBootFramework());
    }

    public function testMissingMethodDefaultsToBootingFramework(): void
    {
        $testCase = new UnitTestFoundationTestCase('missingMethod');

        $this->assertTrue($testCase->shouldBootFramework());
    }

    public function testUnitTestStillRunsInsideCoroutine(): void
    {
        $testCase = new UnitTestFoundationTestCase('unitMethod');

        $testCase->runSetUp();
        $testCase->runTestMethod('unitMethod');
        $testCase->runTearDown();

        $this->assertTrue($testCase->ranInsideCoroutine);
    }

    public function testUnitTestSkipsCoroutineFrameworkLifecycleHooks(): void
    {
        $testCase = new UnitTestFoundationTestCase('unitMethod');

        $testCase->runSetUp();
        $testCase->runTestMethod('unitMethod');
        $testCase->runTearDown();

        $this->assertFalse($testCase->setUpInCoroutineCalled);
        $this->assertFalse($testCase->tearDownInCoroutineCalled);
    }

    public function testFrameworkTestRunsCoroutineFrameworkLifecycleHooks(): void
    {
        $testCase = new UnitTestFoundationTestCase('frameworkMethod');

        $testCase->runTestMethod('frameworkMethod');

        $this->assertTrue($testCase->setUpInCoroutineCalled);
        $this->assertTrue($testCase->tearDownInCoroutineCalled);
    }

    public function testUnitTestWithRefreshDatabaseSkipsDatabaseCoroutineHooks(): void
    {
        $testCase = new UnitTestRefreshDatabaseTestCase('unitMethod');

        $testCase->runSetUp();
        $testCase->runTestMethod('unitMethod');
        $testCase->runTearDown();

        $this->assertTrue($testCase->ranInsideCoroutine);
        $this->assertSame(0, $testCase->applicationCreationCount);
    }

    public function testUnitTestWithDatabaseTransactionsSkipsDatabaseCoroutineHooks(): void
    {
        $testCase = new UnitTestDatabaseTransactionsTestCase('unitMethod');

        $testCase->runSetUp();
        $testCase->runTestMethod('unitMethod');
        $testCase->runTearDown();

        $this->assertTrue($testCase->ranInsideCoroutine);
        $this->assertSame(0, $testCase->applicationCreationCount);
    }

    public function testCoroutineCleanupContinuesAfterATeardownHookFails(): void
    {
        $testCase = new FailingCoroutineCleanupTestCase('frameworkMethod');

        try {
            $testCase->runTestMethod('frameworkMethod');
            $this->fail('Expected coroutine teardown to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('first trait cleanup failed', $exception->getMessage());
        }

        $this->assertTrue($testCase->laterTraitCleanupRan);
        $this->assertTrue($testCase->workerExitChildResumed);
        $this->assertNotNull($testCase->nativeTimerId);
        $this->assertFalse(SwooleTimer::exists($testCase->nativeTimerId));
    }

    public function testTestBodyFailureRemainsPrimaryWhenCleanupAlsoFails(): void
    {
        $testCase = new FailingBodyAndCleanupTestCase('failingMethod');

        try {
            $testCase->runTestMethod('failingMethod');
            $this->fail('Expected the test body to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('test body failed', $exception->getMessage());
        }

        $this->assertTrue($testCase->cleanupAttempted);
    }
}

class UnitTestFoundationTestCase extends FoundationTestCase
{
    use UnitTestCoroutineLifecycleHookTrait;

    public int $applicationCreationCount = 0;

    public bool $ranInsideCoroutine = false;

    public function runSetUp(): void
    {
        $this->setUp();
    }

    public function runTearDown(): void
    {
        $this->tearDown();
    }

    public function runTestMethod(string $method): mixed
    {
        return $this->invokeTestMethod($method, []);
    }

    public function shouldBootFramework(): bool
    {
        return $this->shouldBootFrameworkForTest();
    }

    #[UnitTest]
    public function unitMethod(): void
    {
        $this->ranInsideCoroutine = Coroutine::getCid() !== -1;
    }

    public function frameworkMethod(): void
    {
        $this->ranInsideCoroutine = Coroutine::getCid() !== -1;
    }

    protected function createApplication(): ApplicationContract
    {
        ++$this->applicationCreationCount;

        throw new RuntimeException('Unit test lifecycle should not boot the application.');
    }
}

class UnitTestRefreshDatabaseTestCase extends UnitTestFoundationTestCase
{
    use RefreshDatabase;
}

class UnitTestDatabaseTransactionsTestCase extends UnitTestFoundationTestCase
{
    use DatabaseTransactions;
}

trait UnitTestCoroutineLifecycleHookTrait
{
    public bool $setUpInCoroutineCalled = false;

    public bool $tearDownInCoroutineCalled = false;

    protected function setUpUnitTestCoroutineLifecycleHookTraitInCoroutine(): void
    {
        $this->setUpInCoroutineCalled = true;
    }

    protected function tearDownUnitTestCoroutineLifecycleHookTraitInCoroutine(): void
    {
        $this->tearDownInCoroutineCalled = true;
    }
}

class FailingCoroutineCleanupTestCase extends UnitTestFoundationTestCase
{
    use ThrowingCoroutineCleanupTrait;
    use RecordingCoroutineCleanupTrait;

    public bool $workerExitChildResumed = false;

    public ?int $nativeTimerId = null;

    public function frameworkMethod(): void
    {
        HypervelCoroutine::create(function (): void {
            CoordinatorManager::until(Constants::WORKER_EXIT)->yield();
            $this->workerExitChildResumed = true;
        });

        $this->nativeTimerId = SwooleTimer::after(60_000, static fn (): null => null);
    }
}

class FailingBodyAndCleanupTestCase extends UnitTestFoundationTestCase
{
    public bool $cleanupAttempted = false;

    public function failingMethod(): never
    {
        throw new RuntimeException('test body failed');
    }

    protected function cleanupTestContext(): void
    {
        $this->cleanupAttempted = true;

        throw new RuntimeException('cleanup failed');
    }
}

trait ThrowingCoroutineCleanupTrait
{
    protected function tearDownThrowingCoroutineCleanupTraitInCoroutine(): never
    {
        throw new RuntimeException('first trait cleanup failed');
    }
}

trait RecordingCoroutineCleanupTrait
{
    public bool $laterTraitCleanupRan = false;

    protected function tearDownRecordingCoroutineCleanupTraitInCoroutine(): void
    {
        $this->laterTraitCleanupRan = true;
    }
}
