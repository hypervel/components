<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Response;
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
        Exceptions::spy()->expects('render')->andReturn(new Response);

        $handler = new FakeNullSessionHandler;
        $this->assertFalse($handler->written);

        Session::extend('fake-null', function () use ($handler): FakeNullSessionHandler {
            return $handler;
        });

        Route::get('/', function (): never {
            throw new TokenMismatchException;
        })->middleware('web');

        $this->get('/');
        $this->assertTrue($handler->written);
    }

    public function testPersistentSaveFailureIsRenderedWithoutRetryFailureEscaping(): void
    {
        $handler = new FailingNullSessionHandler;

        Session::extend('failing-null', fn (): FailingNullSessionHandler => $handler);

        Route::get('/', fn (): string => 'response')->middleware('web');

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

    public function testReadOnlySessionIsNotPersistedOrGarbageCollected(): void
    {
        $handler = new FakeNullSessionHandler;

        Session::extend('fake-null', fn (): FakeNullSessionHandler => $handler);

        Route::get('/', fn (): string => 'response')->middleware('web')->readOnlySession();

        $this->app->make('config')->set('session.lottery', [1, 1]);

        $this->get('/')->assertOk();

        $this->assertFalse($handler->written);
        $this->assertFalse($handler->collected);
    }

    public function testReadOnlySessionIsNotPersistedWhenAnExceptionIsThrownFromRoute(): void
    {
        Exceptions::spy()->expects('render')->andReturn(new Response);

        $handler = new FakeNullSessionHandler;

        Session::extend('fake-null', fn (): FakeNullSessionHandler => $handler);

        Route::get('/', function (): never {
            throw new TokenMismatchException;
        })->middleware('web')->readOnlySession();

        $this->get('/');

        $this->assertFalse($handler->written);
    }

    /**
     * Configure the test session driver.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');
        $config->set('app.key', Str::random(32));
        $config->set('session.driver', 'fake-null');
        $config->set('session.expire_on_close', true);
    }
}

class FakeNullSessionHandler extends NullSessionHandler
{
    public bool $written = false;

    public bool $collected = false;

    /**
     * Record that the session was saved.
     */
    public function write(string $sessionId, string $data): bool
    {
        $this->written = true;

        return true;
    }

    /**
     * Record that expired sessions were collected.
     */
    public function gc(int $lifetime): int
    {
        $this->collected = true;

        return 0;
    }
}

class FailingNullSessionHandler extends NullSessionHandler
{
    public int $writeCount = 0;

    /**
     * Fail to persist the session.
     */
    public function write(string $sessionId, string $data): bool
    {
        ++$this->writeCount;

        throw new RuntimeException('Unable to persist the session.');
    }
}
