<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Session\CacheBasedSessionHandler;
use Hypervel\Support\Facades\Session as SessionFacade;
use Hypervel\Testbench\TestCase;

class InteractsWithSessionTest extends TestCase
{
    public function testWithSessionSetsSessionData(): void
    {
        $this->withSession(['foo' => 'bar', 'baz' => 'qux']);

        $session = $this->app->make('session');

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
        $this->assertTrue($session->has('baz'));
        $this->assertSame('qux', $session->get('baz'));
    }

    public function testWithSessionWorksWithCacheDriver(): void
    {
        // Use a cache-based session driver to test that startSession()
        // properly initializes a session ID with strict type hints
        SessionFacade::extend('array-cache', function ($app): CacheBasedSessionHandler {
            return new CacheBasedSessionHandler(
                clone $app->make('cache')->store('array'),
                $app->make('config')->get('session.lifetime')
            );
        });

        $this->app->make('config')->set('session.driver', 'array-cache');

        $this->withSession(['cache_test' => 'value']);

        $session = $this->app->make('session');

        $this->assertTrue($session->has('cache_test'));
        $this->assertSame('value', $session->get('cache_test'));
    }

    public function testSessionMethodSetsSessionData(): void
    {
        $this->session(['key' => 'value']);

        $session = $this->app->make('session');

        $this->assertTrue($session->has('key'));
        $this->assertSame('value', $session->get('key'));
    }

    public function testFlushSessionClearsAllData(): void
    {
        $this->withSession(['foo' => 'bar', 'baz' => 'qux']);

        $session = $this->app->make('session');
        $this->assertTrue($session->has('foo'));

        $this->flushSession();

        $this->assertFalse($session->has('foo'));
        $this->assertFalse($session->has('baz'));
    }
}
