<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Testing\Fakes;

use Exception;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Testing\Fakes\ExceptionHandlerFake;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class ExceptionHandlerFakeTest extends TestCase
{
    protected function tearDown(): void
    {
        Exceptions::clearResolvedInstances();
        parent::tearDown();
    }

    public function testFakeReturnsExceptionHandlerFake(): void
    {
        $fake = Exceptions::fake();

        $this->assertInstanceOf(ExceptionHandlerFake::class, $fake);
        $this->assertInstanceOf(ExceptionHandlerFake::class, Exceptions::getFacadeRoot());
        $this->assertInstanceOf(Handler::class, $fake->handler());
    }

    public function testFakeCalledTwiceReturnsNewFakeWithOriginalHandler(): void
    {
        $fake1 = Exceptions::fake();
        $fake2 = Exceptions::fake();

        $this->assertNotSame($fake1, $fake2);
        $this->assertInstanceOf(Handler::class, $fake2->handler());
    }

    public function testAssertReportedWithClassString(): void
    {
        Exceptions::fake();

        Exceptions::report(new RuntimeException('test'));

        Exceptions::assertReported(RuntimeException::class);
    }

    public function testAssertReportedWithClosure(): void
    {
        Exceptions::fake();

        Exceptions::report(new RuntimeException('test message'));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'test message');
    }

    public function testAssertReportedFailsWhenExceptionNotReported(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The expected [InvalidArgumentException] exception was not reported.');

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test'));

        Exceptions::assertReported(InvalidArgumentException::class);
    }

    public function testAssertReportedWithClosureFailsWhenNoMatch(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The expected [RuntimeException] exception was not reported.');

        Exceptions::fake();

        Exceptions::report(new RuntimeException('wrong message'));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'right message');
    }

    public function testAssertReportedCount(): void
    {
        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));
        Exceptions::report(new RuntimeException('test 2'));

        Exceptions::assertReportedCount(2);
    }

    public function testAssertReportedCountFails(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The total number of exceptions reported was 2 instead of 1.');

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));
        Exceptions::report(new RuntimeException('test 2'));

        Exceptions::assertReportedCount(1);
    }

    public function testAssertNotReported(): void
    {
        Exceptions::fake();

        Exceptions::report(new RuntimeException('test'));

        Exceptions::assertNotReported(InvalidArgumentException::class);
    }

    public function testAssertNotReportedFails(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The expected [RuntimeException] exception was reported.');

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test'));

        Exceptions::assertNotReported(RuntimeException::class);
    }

    public function testAssertNotReportedWithClosureFails(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The expected [RuntimeException] exception was reported.');

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test message'));

        Exceptions::assertNotReported(fn (RuntimeException $e) => $e->getMessage() === 'test message');
    }

    public function testAssertNothingReported(): void
    {
        Exceptions::fake();

        Exceptions::assertNothingReported();
    }

    public function testAssertNothingReportedFails(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The following exceptions were reported: RuntimeException, InvalidArgumentException.');

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));
        Exceptions::report(new InvalidArgumentException('test 2'));

        Exceptions::assertNothingReported();
    }

    public function testReportedReturnsAllReportedExceptions(): void
    {
        Exceptions::fake();

        $exception1 = new RuntimeException('test 1');
        $exception2 = new InvalidArgumentException('test 2');

        Exceptions::report($exception1);
        Exceptions::report($exception2);

        $reported = Exceptions::reported();

        $this->assertCount(2, $reported);
        $this->assertSame($exception1, $reported[0]);
        $this->assertSame($exception2, $reported[1]);
    }

    public function testFakeWithSpecificExceptionsOnlyFakesThose(): void
    {
        Exceptions::fake([RuntimeException::class]);

        Exceptions::report(new RuntimeException('test 1'));
        Exceptions::report(new RuntimeException('test 2'));

        Exceptions::assertReported(RuntimeException::class);
        Exceptions::assertReportedCount(2);
        Exceptions::assertNotReported(InvalidArgumentException::class);
    }

    public function testThrowOnReport(): void
    {
        Exceptions::fake()->throwOnReport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test exception');

        Exceptions::report(new RuntimeException('test exception'));
    }

    public function testThrowFirstReported(): void
    {
        Exceptions::fake();

        Exceptions::report(new RuntimeException('first'));
        Exceptions::report(new InvalidArgumentException('second'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('first');

        Exceptions::throwFirstReported();
    }

    public function testThrowFirstReportedDoesNothingWhenEmpty(): void
    {
        Exceptions::fake();

        Exceptions::throwFirstReported();

        $this->assertTrue(true); // No exception thrown
    }

    public function testSetHandler(): void
    {
        $fake = Exceptions::fake();
        $newHandler = $this->createMock(ExceptionHandler::class);

        $result = $fake->setHandler($newHandler);

        $this->assertSame($fake, $result);
        $this->assertSame($newHandler, $fake->handler());
    }
}
