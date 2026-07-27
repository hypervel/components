<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session;

use Hypervel\Session\NullSessionHandler;
use Hypervel\Session\TokenMismatchException;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Session;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use RuntimeException;

class SessionPersistenceTest extends TestCase
{
    public function testSessionIsPersistedEvenIfExceptionIsThrownFromRoute(): void
    {
        $handler = new FakeNullSessionHandler;
        $this->assertFalse($handler->written);

        Session::extend('fake-null', function () use ($handler) {
            return $handler;
        });

        Route::get('/', function () {
            throw new TokenMismatchException;
        })->middleware('web');

        $this->get('/');
        $this->assertTrue($handler->written);
    }

    public function testPersistentSaveFailureIsRenderedWithoutRetryFailureEscaping(): void
    {
        $handler = new FailingNullSessionHandler;

        Session::extend('failing-null', fn () => $handler);

        Route::get('/', fn () => 'response')->middleware('web');

        $this->app->make('config')->set('session.driver', 'failing-null');
        Exceptions::fake();

        $response = $this->get('/');

        $response->assertInternalServerError();
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === 'Unable to persist the session.'
        );
        Exceptions::assertReportedCount(1);
        $this->assertSame(2, $handler->writeCount);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', Str::random(32));
        $app['config']->set('session.driver', 'fake-null');
        $app['config']->set('session.expire_on_close', true);
    }
}

class FakeNullSessionHandler extends NullSessionHandler
{
    public bool $written = false;

    public function write(string $sessionId, string $data): bool
    {
        $this->written = true;

        return true;
    }
}

class FailingNullSessionHandler extends NullSessionHandler
{
    public int $writeCount = 0;

    public function write(string $sessionId, string $data): bool
    {
        ++$this->writeCount;

        throw new RuntimeException('Unable to persist the session.');
    }
}
