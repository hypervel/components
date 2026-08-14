<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

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
