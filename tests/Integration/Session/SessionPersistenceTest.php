<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\Response;
use Hypervel\Session\NullSessionHandler;
use Hypervel\Session\TokenMismatchException;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Session;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class SessionPersistenceTest extends TestCase
{
    public function testSessionIsPersistedEvenIfExceptionIsThrownFromRoute()
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

    protected function defineEnvironment($app): void
    {
        $app->instance(
            ExceptionHandler::class,
            $handler = m::mock(ExceptionHandler::class)->shouldIgnoreMissing()
        );

        $handler->shouldReceive('render')->andReturn(new Response);

        $app['config']->set('app.key', Str::random(32));
        $app['config']->set('session.driver', 'fake-null');
        $app['config']->set('session.expire_on_close', true);
    }
}

class FakeNullSessionHandler extends NullSessionHandler
{
    public bool $written = false;

    public function write($sessionId, $data): bool
    {
        $this->written = true;

        return true;
    }
}
