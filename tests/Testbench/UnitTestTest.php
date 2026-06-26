<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Coroutine;

class UnitTestTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testUnitTestSkipsTestbenchApplicationBoot(): void
    {
        $testCase = new UnitTestTestbenchTestCase('unitMethod');

        $testCase->runSetUp();
        $testCase->runTestMethod('unitMethod');
        $testCase->runTearDown();

        $this->assertNull($testCase->application());
        $this->assertTrue($testCase->ranInsideCoroutine);
        $this->assertSame(0, $testCase->applicationCreationCount);
    }
}

class UnitTestTestbenchTestCase extends TestbenchTestCase
{
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

    public function application(): ?ApplicationContract
    {
        return $this->app;
    }

    #[UnitTest]
    public function unitMethod(): void
    {
        $this->ranInsideCoroutine = Coroutine::getCid() !== -1;
    }

    public function createApplication(): ApplicationContract
    {
        ++$this->applicationCreationCount;

        throw new RuntimeException('Unit test lifecycle should not boot Testbench.');
    }
}
