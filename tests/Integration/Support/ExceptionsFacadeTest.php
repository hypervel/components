<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support;

use ErrorException;
use Exception;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Validator;
use Hypervel\Support\Testing\Fakes\ExceptionHandlerFake;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionClass;
use RuntimeException;
use Throwable;

class ExceptionsFacadeTest extends TestCase
{
    public function testFakeAssertReported(): void
    {
        Exceptions::fake();

        Exceptions::report($thrownException = new RuntimeException('test 1'));
        report(new RuntimeException('test 2'));

        Exceptions::assertReported(RuntimeException::class);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'test 1');
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'test 2');
        Exceptions::assertReportedCount(2);

        $reported = Exceptions::reported();
        $this->assertCount(2, $reported);
        $this->assertSame($thrownException, $reported[0]);
    }

    public function testFakeAssertReportedCount(): void
    {
        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));
        report(new RuntimeException('test 2'));

        Exceptions::assertReportedCount(2);
    }

    public function testFakeAssertReportedCountMayFail(): void
    {
        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));
        report(new RuntimeException('test 2'));

        $this->expectExceptionObject(new ExpectationFailedException('The total number of exceptions reported was 2 instead of 1.'));

        Exceptions::assertReportedCount(1);
    }

    public function testFakeAssertReportedWithFakedExceptions(): void
    {
        Exceptions::fake([
            RuntimeException::class,
        ]);

        Exceptions::report(new RuntimeException('test 1'));
        report(new RuntimeException('test 2'));
        report(new InvalidArgumentException('test 3'));

        Exceptions::assertReported(RuntimeException::class);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'test 1');
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'test 2');

        Exceptions::assertNotReported(InvalidArgumentException::class);
        Exceptions::assertReportedCount(2);
    }

    public function testFakeAssertReportedAsStringMayFail(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The expected [InvalidArgumentException] exception was not reported.'));

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(InvalidArgumentException::class);
    }

    public function testFakeAssertReportedAsClosureMayFail(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The expected [InvalidArgumentException] exception was not reported.'));

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (InvalidArgumentException $e): bool => $e->getMessage() === 'test 2');
    }

    public function testFakeAssertReportedWithFakedExceptionsMayFail(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The expected [RuntimeException] exception was not reported.'));

        Exceptions::fake(InvalidArgumentException::class);

        Exceptions::report(new InvalidArgumentException('test 1'));
        report(new RuntimeException('test 2'));

        Exceptions::assertReported(InvalidArgumentException::class);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function testFakeAssertNotReported(): void
    {
        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));
        report(new RuntimeException('test 2'));

        Exceptions::assertNotReported(InvalidArgumentException::class);
        Exceptions::assertNotReported(fn (InvalidArgumentException $e): bool => $e->getMessage() === 'test 1');
        Exceptions::assertNotReported(fn (InvalidArgumentException $e): bool => $e->getMessage() === 'test 2');
        Exceptions::assertNotReported(fn (InvalidArgumentException $e): bool => $e->getMessage() === 'test 3');
        Exceptions::assertNotReported(fn (InvalidArgumentException $e): bool => $e->getMessage() === 'test 4');

        Exceptions::assertReportedCount(2);
    }

    public function testFakeAssertNotReportedWithFakedExceptions(): void
    {
        Exceptions::fake([
            InvalidArgumentException::class,
        ]);

        report(new RuntimeException('test 2'));

        Exceptions::assertNotReported(InvalidArgumentException::class);
        Exceptions::assertNotReported(RuntimeException::class);
    }

    public function testFakeAssertNotReportedMayFail(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The expected [RuntimeException] exception was reported.'));

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));

        Exceptions::assertNotReported(RuntimeException::class);
    }

    public function testFakeAssertNotReportedAsClosureMayFail(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The expected [RuntimeException] exception was reported.'));

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));

        Exceptions::assertNotReported(fn (RuntimeException $e): bool => $e->getMessage() === 'test 1');
    }

    public function testResolvesExceptionHandler(): void
    {
        $this->assertInstanceOf(
            ExceptionHandler::class,
            Exceptions::getFacadeRoot()
        );
    }

    public function testFakeAssertNothingReported(): void
    {
        Exceptions::fake();

        Exceptions::assertNothingReported();
    }

    public function testFakeAssertNothingReportedWithFakedExceptions(): void
    {
        Exceptions::fake([
            InvalidArgumentException::class,
        ]);

        report(new RuntimeException('test 1'));

        Exceptions::assertNothingReported();
    }

    public function testFakeAssertNothingReportedMayFail(): void
    {
        $this->expectExceptionObject(new ExpectationFailedException('The following exceptions were reported: RuntimeException, RuntimeException, InvalidArgumentException.'));

        Exceptions::fake();

        Exceptions::report(new RuntimeException('test 1'));
        report(new RuntimeException('test 2'));
        report(new InvalidArgumentException('test 3'));

        Exceptions::assertNothingReported();
    }

    public function testFakeMethodReturnsExceptionHandlerFake(): void
    {
        $this->assertInstanceOf(ExceptionHandlerFake::class, $fake = Exceptions::fake());
        $this->assertInstanceOf(ExceptionHandlerFake::class, Exceptions::getFacadeRoot());
        $this->assertInstanceOf(Handler::class, $fake->handler());

        $this->assertInstanceOf(ExceptionHandlerFake::class, $fake = Exceptions::fake());
        $this->assertInstanceOf(ExceptionHandlerFake::class, Exceptions::getFacadeRoot());
        $this->assertInstanceOf(Handler::class, $fake->handler());
    }

    public function testReportedExceptionsAreNotThrownByDefault(): void
    {
        report(new Exception('Test exception'));

        $this->assertTrue(true);
    }

    public function testReportedExceptionsAreNotThrownByDefaultWithExceptionHandling(): void
    {
        Route::get('/', function (): void {
            report(new Exception('Test exception'));
        });

        $this->get('/')->assertStatus(200);
    }

    public function testReportedExceptionsAreNotThrownByDefaultWithoutExceptionHandling(): void
    {
        $this->withoutExceptionHandling();

        Route::get('/', function (): void {
            report(new Exception('Test exception'));
        });

        $this->get('/')->assertStatus(200);
    }

    public function testThrowOnReport(): void
    {
        Exceptions::fake()->throwOnReport();

        $this->expectExceptionObject(new Exception('Test exception'));

        report(new Exception('Test exception'));
    }

    public function testThrowOnReportDoesNotThrowExceptionsThatShouldNotBeReported(): void
    {
        Exceptions::fake()->throwOnReport();

        Route::get('/302', function (): void {
            Validator::validate(['name' => ''], ['name' => 'required']);
        });

        $this->get('/302')->assertStatus(302);

        Route::get('/404', function (): never {
            throw new ModelNotFoundException;
        });

        $this->get('/404')->assertStatus(404);

        Exceptions::assertReportedCount(0);
    }

    public function testThrowOnReportWithExceptionHandling(): void
    {
        Exceptions::fake()->throwOnReport();

        Route::get('/', function (): void {
            report(new Exception('Test exception'));
        });

        $this->expectExceptionObject(new Exception('Test exception'));

        $this->get('/');
    }

    public function testThrowOnReportWithoutExceptionHandling(): void
    {
        Exceptions::fake()->throwOnReport();

        $this->withoutExceptionHandling();

        Route::get('/', function (): void {
            report(new Exception('Test exception'));
        });

        $this->expectExceptionObject(new Exception('Test exception'));

        $this->get('/');
    }

    public function testThrowOnReportRegardlessOfTheCallingOrderOfWithoutExceptionHandling(): void
    {
        Exceptions::fake()->throwOnReport();

        $this
            ->withoutExceptionHandling()
            ->withExceptionHandling()
            ->withoutExceptionHandling();

        Route::get('/', function (): void {
            rescue(fn (): never => throw new Exception('Test exception'));
        });

        $this->expectExceptionObject(new Exception('Test exception'));

        $this->get('/');
    }

    public function testThrowOnReportRegardlessOfTheCallingOrderOfWithExceptionHandling(): void
    {
        Exceptions::fake()->throwOnReport();

        $this->withoutExceptionHandling()
            ->withExceptionHandling()
            ->withoutExceptionHandling()
            ->withExceptionHandling();

        Route::get('/', function (): void {
            rescue(fn (): never => throw new Exception('Test exception'));
        });

        $this->expectExceptionObject(new Exception('Test exception'));

        $this->get('/');
    }

    public function testThrowOnReportWithFakedExceptions(): void
    {
        Exceptions::fake([InvalidArgumentException::class])->throwOnReport();

        $this->expectException(InvalidArgumentException::class);

        report(new Exception('Test exception'));
        report(new RuntimeException('Test exception'));
        report(new InvalidArgumentException('Test exception'));
    }

    public function testThrowOnReportWithFakedExceptionsFromFacade(): void
    {
        Exceptions::fake([InvalidArgumentException::class])->throwOnReport();

        $this->expectException(InvalidArgumentException::class);

        report(new Exception('Test exception'));
        report(new RuntimeException('Test exception'));
        Exceptions::assertReportedCount(0);

        report(new InvalidArgumentException('Test exception'));
    }

    public function testThrowOnReporEvenWhenAppReportablesReturnFalse(): void
    {
        app(ExceptionHandler::class)->reportable(function (Throwable $e): false {
            return false;
        });

        Exceptions::fake()->throwOnReport();

        $this->expectExceptionObject(new Exception('Test exception'));

        report(new Exception('Test exception'));
    }

    public function testAppReportablesAreNotCalledIfExceptionIsNotFaked(): void
    {
        app(ExceptionHandler::class)->reportable(function (Throwable $e): never {
            throw new InvalidArgumentException($e->getMessage());
        });

        Exceptions::fake([RuntimeException::class, Exception::class]);

        report(new Exception('My exception message'));

        Exceptions::assertReported(Exception::class);
    }

    public function testThrowOnReportLeaveAppReportablesUntouched(): void
    {
        app(ExceptionHandler::class)->reportable(function (Throwable $e): never {
            throw new InvalidArgumentException($e->getMessage());
        });

        Exceptions::fake([RuntimeException::class])->throwOnReport();

        $this->expectExceptionObject(new InvalidArgumentException('My exception message'));

        report(new Exception('My exception message'));
    }

    public function testThrowReportedExceptions(): void
    {
        Exceptions::fake();

        $this->expectExceptionObject(new Exception('Test exception'));

        report(new Exception('Test exception'));

        Exceptions::throwFirstReported();
    }

    public function testThrowReportedExceptionsWithFakedExceptions(): void
    {
        Exceptions::fake([InvalidArgumentException::class]);

        $this->expectExceptionObject(new InvalidArgumentException('Test exception'));

        report(new RuntimeException('Test exception'));
        report(new InvalidArgumentException('Test exception'));

        Exceptions::throwFirstReported();
    }

    public function testThrowReportedExceptionsWhenThereIsNone(): void
    {
        Exceptions::fake();

        Exceptions::throwFirstReported();

        Exceptions::fake([InvalidArgumentException::class]);

        report(new RuntimeException('Test exception'));

        Exceptions::throwFirstReported();

        $this->expectNotToPerformAssertions();
    }

    public function testFakingExceptionsThatShouldNotBeReportedWithExceptionHandling(): void
    {
        Exceptions::fake();

        Route::get('/302', function (): void {
            Validator::validate(['name' => ''], ['name' => 'required']);
        });

        $this->get('/302')->assertStatus(302);

        Route::get('/404', function (): never {
            throw new ModelNotFoundException;
        });

        $this->get('/404')->assertStatus(404);

        report(new ModelNotFoundException);

        Exceptions::assertNothingReported();
    }

    public function testFakingExceptionsThatShouldNotBeReportedWithRescueAndWithoutExceptionHandling(): void
    {
        Exceptions::fake();

        $this->withoutExceptionHandling();

        Route::get('/validation', function (): void {
            rescue(fn (): array => Validator::validate(['name' => ''], ['name' => 'required']));
        });

        $this->get('/validation')->assertStatus(200);

        Route::get('/model', function (): void {
            rescue(fn (): never => throw new ModelNotFoundException);
        });

        $this->get('/model')->assertStatus(200);

        rescue(fn (): never => throw new ModelNotFoundException);

        Exceptions::assertReportedCount(3);
    }

    public function testRescue(): void
    {
        Exceptions::fake();

        rescue(fn (): never => throw new Exception('Test exception'));

        Exceptions::assertReported(Exception::class);
    }

    public function testRescueWithoutReport(): void
    {
        Exceptions::fake();

        rescue(fn (): never => throw new Exception('Test exception'), null, false);

        Exceptions::assertNothingReported();
    }

    public function testFlowBetweenFakeAndTestExceptionHandling(): void
    {
        $this->assertInstanceOf(Handler::class, app(ExceptionHandler::class));

        Exceptions::fake();
        $this->assertInstanceOf(ExceptionHandlerFake::class, app(ExceptionHandler::class));
        $this->assertInstanceOf(Handler::class, Exceptions::fake()->handler());
        $this->assertFalse((new ReflectionClass(Exceptions::fake()->handler()))->isAnonymous());

        Exceptions::fake();
        $this->assertInstanceOf(ExceptionHandlerFake::class, app(ExceptionHandler::class));
        $this->assertInstanceOf(Handler::class, Exceptions::fake()->handler());
        $this->assertFalse((new ReflectionClass(Exceptions::fake()->handler()))->isAnonymous());

        $this->withoutExceptionHandling();
        $this->assertInstanceOf(ExceptionHandlerFake::class, app(ExceptionHandler::class));
        $this->assertInstanceOf(ExceptionHandler::class, Exceptions::fake()->handler());
        $this->assertTrue((new ReflectionClass(Exceptions::fake()->handler()))->isAnonymous());

        $this->withExceptionHandling();
        $this->assertInstanceOf(ExceptionHandlerFake::class, app(ExceptionHandler::class));
        $this->assertInstanceOf(ExceptionHandler::class, Exceptions::fake()->handler());
        $this->assertFalse((new ReflectionClass(Exceptions::fake()->handler()))->isAnonymous());

        Exceptions::fake();
        $this->assertInstanceOf(ExceptionHandlerFake::class, app(ExceptionHandler::class));
        $this->assertInstanceOf(Handler::class, Exceptions::fake()->handler());
        $this->assertFalse((new ReflectionClass(Exceptions::fake()->handler()))->isAnonymous());
    }

    public function testFlowBetweenTestExceptionHandlingAndFake(): void
    {
        $this->withoutExceptionHandling();
        $this->assertTrue((new ReflectionClass(app(ExceptionHandler::class)))->isAnonymous());

        Exceptions::fake();
        $this->assertInstanceOf(ExceptionHandlerFake::class, app(ExceptionHandler::class));
        $this->assertInstanceOf(ExceptionHandler::class, Exceptions::fake()->handler());
        $this->assertTrue((new ReflectionClass(Exceptions::fake()->handler()))->isAnonymous());

        Exceptions::fake();
        $this->assertInstanceOf(ExceptionHandlerFake::class, app(ExceptionHandler::class));
        $this->assertInstanceOf(ExceptionHandler::class, Exceptions::fake()->handler());
        $this->assertTrue((new ReflectionClass(Exceptions::fake()->handler()))->isAnonymous());

        $this->withExceptionHandling();
        $this->assertInstanceOf(ExceptionHandlerFake::class, app(ExceptionHandler::class));
        $this->assertInstanceOf(Handler::class, Exceptions::fake()->handler());
        $this->assertFalse((new ReflectionClass(Exceptions::fake()->handler()))->isAnonymous());
    }

    public function testWithDeprecationHandling(): void
    {
        Exceptions::fake();

        Route::get('/', function (): void {
            trigger_error('Something is deprecated', E_USER_DEPRECATED);
        });

        $this->get('/')->assertStatus(200);

        Exceptions::assertNothingReported();
    }

    public function testWithoutDeprecationHandler(): void
    {
        Exceptions::fake();

        $this->withoutDeprecationHandling();

        Route::get('/', function (): void {
            trigger_error('Something is deprecated', E_USER_DEPRECATED);
        });

        $this->get('/')->assertStatus(500);

        Exceptions::assertReported(function (ErrorException $e): bool {
            return $e->getMessage() === 'Something is deprecated';
        });

        Exceptions::assertReportedCount(1);
    }
}
