<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Session\Session;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithSessionTest extends TestCase
{
    public function testWithSessionSetsSessionData(): void
    {
        $this->withSession(['foo' => 'bar', 'baz' => 'qux']);

        $session = $this->app->get(Session::class);

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
        $this->assertTrue($session->has('baz'));
        $this->assertSame('qux', $session->get('baz'));
    }

    public function testWithSessionWorksWithCacheDriver(): void
    {
        // Use cache driver which has strict type hints on sessionId
        // This tests that startSession() properly initializes a session ID
        $this->app->get(ConfigInterface::class)->set('session.driver', 'redis');

        $this->withSession(['cache_test' => 'value']);

        $session = $this->app->get(Session::class);

        $this->assertTrue($session->has('cache_test'));
        $this->assertSame('value', $session->get('cache_test'));
    }

    public function testSessionMethodSetsSessionData(): void
    {
        $this->session(['key' => 'value']);

        $session = $this->app->get(Session::class);

        $this->assertTrue($session->has('key'));
        $this->assertSame('value', $session->get('key'));
    }

    public function testFlushSessionClearsAllData(): void
    {
        $this->withSession(['foo' => 'bar', 'baz' => 'qux']);

        $session = $this->app->get(Session::class);
        $this->assertTrue($session->has('foo'));

        $this->flushSession();

        $this->assertFalse($session->has('foo'));
        $this->assertFalse($session->has('baz'));
    }
}
