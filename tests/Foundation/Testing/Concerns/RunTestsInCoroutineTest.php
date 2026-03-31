<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Coroutine;

/**
 * @internal
 * @coversNothing
 */
class RunTestsInCoroutineTest extends TestCase
{
    public function testSetUpCoroutineTestSwapsNameOutsideCoroutine()
    {
        $testCase = new CoroutineTestStub('myTestMethod');

        $this->assertSame(-1, Coroutine::getCid());
        $this->assertSame('myTestMethod', $testCase->name());

        $method = new ReflectionMethod($testCase, 'setUpCoroutineTest');
        $method->invoke($testCase);

        $this->assertSame('runTestsInCoroutine', $testCase->name());

        $realName = new ReflectionProperty($testCase, 'realTestName');
        $this->assertSame('myTestMethod', $realName->getValue($testCase));
    }

    public function testSetUpCoroutineTestDoesNotSwapWhenCoroutineDisabled()
    {
        $testCase = new CoroutineDisabledTestStub('myTestMethod');

        $method = new ReflectionMethod($testCase, 'setUpCoroutineTest');
        $method->invoke($testCase);

        $this->assertSame('myTestMethod', $testCase->name());
    }

    public function testSetUpCoroutineTestIsNoOpInsideCoroutine()
    {
        $testCase = new CoroutineTestStub('myTestMethod');

        \Hypervel\Coroutine\run(function () use ($testCase) {
            $this->assertGreaterThan(-1, Coroutine::getCid());

            $method = new ReflectionMethod($testCase, 'setUpCoroutineTest');
            $method->invoke($testCase);

            $this->assertSame('myTestMethod', $testCase->name());
        });
    }

    public function testRunTestsInCoroutineExecutesInCoroutine()
    {
        $testCase = new CoroutineTestStub('myTestMethod');

        $setUp = new ReflectionMethod($testCase, 'setUpCoroutineTest');
        $setUp->invoke($testCase);

        $run = new ReflectionMethod($testCase, 'runTestsInCoroutine');
        $run->invoke($testCase);

        $this->assertTrue($testCase->executedInCoroutine);
    }

    public function testRunTestsInCoroutineRestoresOriginalName()
    {
        $testCase = new CoroutineTestStub('myTestMethod');

        $setUp = new ReflectionMethod($testCase, 'setUpCoroutineTest');
        $setUp->invoke($testCase);

        $this->assertSame('runTestsInCoroutine', $testCase->name());

        $run = new ReflectionMethod($testCase, 'runTestsInCoroutine');
        $run->invoke($testCase);

        $this->assertSame('myTestMethod', $testCase->name());
    }

    public function testRunTestsInCoroutinePropagatesExceptions()
    {
        $testCase = new CoroutineExceptionTestStub('throwingMethod');

        $setUp = new ReflectionMethod($testCase, 'setUpCoroutineTest');
        $setUp->invoke($testCase);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception from coroutine');

        $run = new ReflectionMethod($testCase, 'runTestsInCoroutine');
        $run->invoke($testCase);
    }
}

/**
 * @internal
 */
class CoroutineTestStub extends \PHPUnit\Framework\TestCase
{
    use RunTestsInCoroutine;

    public bool $executedInCoroutine = false;

    public function myTestMethod(): void
    {
        $this->executedInCoroutine = Coroutine::getCid() > -1;
    }
}

/**
 * @internal
 */
class CoroutineDisabledTestStub extends \PHPUnit\Framework\TestCase
{
    use RunTestsInCoroutine;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->enableCoroutine = false;
    }

    public function myTestMethod(): void
    {
    }
}

/**
 * @internal
 */
class CoroutineExceptionTestStub extends \PHPUnit\Framework\TestCase
{
    use RunTestsInCoroutine;

    public function throwingMethod(): void
    {
        throw new \RuntimeException('Test exception from coroutine');
    }
}
