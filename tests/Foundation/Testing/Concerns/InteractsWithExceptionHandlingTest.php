<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Foundation\Testing\Concerns\WithoutExceptionHandlingHandler;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Testing\Fakes\ExceptionHandlerFake;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithExceptionHandlingTest extends TestCase
{
    public function testWithoutExceptionHandlingRethrowsExceptions()
    {
        $this->withoutExceptionHandling();

        Route::get('/error', function () {
            throw new RuntimeException('Something went wrong');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Something went wrong');

        $this->get('/error');
    }

    public function testWithoutExceptionHandlingReplacesHandler()
    {
        $this->withoutExceptionHandling();

        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertInstanceOf(WithoutExceptionHandlingHandler::class, $handler);
    }

    public function testWithExceptionHandlingRestoresOriginalHandler()
    {
        $originalHandler = $this->app->make(ExceptionHandler::class);

        $this->withoutExceptionHandling();
        $this->withExceptionHandling();

        $restoredHandler = $this->app->make(ExceptionHandler::class);

        $this->assertSame($originalHandler, $restoredHandler);
    }

    public function testWithoutExceptionHandlingAllowsExceptedExceptions()
    {
        $this->withoutExceptionHandling([NotFoundHttpException::class]);

        Route::get('/exists', function () {
            return 'ok';
        });

        // NotFoundHttpException is in the $except list, so it should be
        // rendered by the original handler rather than rethrown.
        $response = $this->get('/nonexistent');
        $response->assertNotFound();
    }

    public function testWithoutExceptionHandlingEnrichesNotFoundMessage()
    {
        $this->withoutExceptionHandling();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('GET');

        $this->get('/nonexistent');
    }

    public function testHandleExceptionsIsAliasForWithoutExceptionHandling()
    {
        $this->handleExceptions([NotFoundHttpException::class]);

        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertInstanceOf(WithoutExceptionHandlingHandler::class, $handler);

        // NotFoundHttpException should be rendered, not rethrown
        $response = $this->get('/nonexistent');
        $response->assertNotFound();
    }

    public function testWithExceptionHandlingRestoresFromFake()
    {
        // First fake the exception handler
        $originalHandler = $this->app->make(ExceptionHandler::class);
        $fake = new ExceptionHandlerFake($originalHandler);
        $this->app->instance(ExceptionHandler::class, $fake);

        // Now disable exception handling
        $this->withoutExceptionHandling();

        // The fake should now have the WithoutExceptionHandlingHandler set
        $currentHandler = $this->app->make(ExceptionHandler::class);
        $this->assertInstanceOf(ExceptionHandlerFake::class, $currentHandler);

        // Restore — should put back the original through the fake
        $this->withExceptionHandling();

        $currentHandler = $this->app->make(ExceptionHandler::class);
        $this->assertInstanceOf(ExceptionHandlerFake::class, $currentHandler);
    }

    public function testAssertThrowsPassesWhenExceptionIsThrown()
    {
        $this->assertThrows(function () {
            throw new RuntimeException('test');
        }, RuntimeException::class);
    }

    public function testAssertThrowsPassesWithMessageCheck()
    {
        $this->assertThrows(
            fn () => throw new RuntimeException('specific message'),
            RuntimeException::class,
            'specific'
        );
    }

    public function testAssertThrowsWithClosurePredicate()
    {
        $this->assertThrows(
            fn () => throw new RuntimeException('test'),
            fn (RuntimeException $e) => $e->getMessage() === 'test'
        );
    }

    public function testAssertDoesntThrowPassesWhenNoExceptionIsThrown()
    {
        $this->assertDoesntThrow(function () {
            // no exception
        });
    }

    public function testWithoutExceptionHandlingShouldReportReturnsFalse()
    {
        $this->withoutExceptionHandling();

        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(new RuntimeException()));
    }
}
