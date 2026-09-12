<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cookie;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Response;
use Hypervel\Session\NullSessionHandler;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Session;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;

class CookieTest extends TestCase
{
    public function testCookieIsSentBackWithProperExpireTimeWhenShouldExpireOnClose(): void
    {
        config(['session.expire_on_close' => true]);

        Route::get('/', function (): string {
            return 'hello world';
        })->middleware('web');

        $response = $this->get('/');
        $this->assertCount(2, $response->headers->getCookies());
        $this->assertEquals(0, $response->headers->getCookies()[1]->getExpiresTime());
    }

    public function testCookieIsSentBackWithProperExpireTimeWithRespectToLifetime(): void
    {
        config(['session.expire_on_close' => false, 'session.lifetime' => 1]);

        Route::get('/', function (): string {
            return 'hello world';
        })->middleware('web');

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $response = $this->get('/');
        $this->assertCount(2, $response->headers->getCookies());
        $this->assertEquals($now->addMinute()->getTimestamp(), $response->headers->getCookies()[1]->getExpiresTime());
    }

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        Exceptions::spy()->shouldReceive('render')->andReturn(new Response);

        $app->make('config')->set('app.key', Str::random(32));
        $app->make('config')->set('session.driver', 'fake-null');

        Session::extend('fake-null', function (): NullSessionHandler {
            return new NullSessionHandler;
        });
    }
}
