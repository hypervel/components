<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Testing\UnitTestCase;
use Mockery as m;
use Mockery\Exception\InvalidCountException;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;

class UnitTestCaseTest extends UnitTestCase
{
    public function testRunsTestMethodsInACoroutine(): void
    {
        $this->assertNotSame(-1, Coroutine::getCid());
    }

    public function testFlushesExceptionStateAndVerifiesMockery(): void
    {
        $testCase = new TestableUnitTestCase('placeholder');
        $mock = m::mock();
        $mock->shouldReceive('expected')->once();
        $mock->expected();

        $testCase->finish();

        $this->assertTrue($testCase->exceptionStateFlushed);
        $this->assertSame(1, $testCase->assertionCount());
        $this->assertNull((new ReflectionProperty(m::class, '_container'))->getValue());
    }

    public function testStillVerifiesMockeryWhenExceptionCleanupFails(): void
    {
        $testCase = new TestableUnitTestCase('placeholder');
        $testCase->failExceptionCleanup = true;
        $mock = m::mock();
        $mock->shouldReceive('expected')->once();
        $mock->expected();

        try {
            $testCase->finish();
            $this->fail('Expected exception cleanup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('exception cleanup failed', $exception->getMessage());
        }

        $this->assertSame(1, $testCase->assertionCount());
        $this->assertNull((new ReflectionProperty(m::class, '_container'))->getValue());
    }

    public function testReportsUnmetMockeryExpectations(): void
    {
        $testCase = new TestableUnitTestCase('placeholder');
        m::mock()->shouldReceive('expected')->once();

        $this->expectException(InvalidCountException::class);

        $testCase->finish();
    }
}

class TestableUnitTestCase extends UnitTestCase
{
    public bool $exceptionStateFlushed = false;

    public bool $failExceptionCleanup = false;

    public function placeholder(): void
    {
    }

    public function finish(): void
    {
        $this->tearDown();
    }

    public function assertionCount(): int
    {
        return $this->numberOfAssertionsPerformed();
    }

    protected function flushExceptionHandlerState(): void
    {
        $this->exceptionStateFlushed = true;

        if ($this->failExceptionCleanup) {
            throw new RuntimeException('exception cleanup failed');
        }
    }
}
