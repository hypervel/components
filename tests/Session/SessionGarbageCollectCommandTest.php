<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Session\SessionManager;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use SessionHandlerInterface;

#[WithConfig('session.driver', 'custom')]
#[WithConfig('session.lifetime', 7)]
class SessionGarbageCollectCommandTest extends TestCase
{
    public function testRegisteredCommandCollectsTheConfiguredSessionDriver(): void
    {
        $handler = m::mock(SessionHandlerInterface::class);
        $handler->expects('gc')->with(420)->andReturn(2);
        $this->app->make(SessionManager::class)->extend('custom', fn () => $handler);

        $this->artisan('session:garbage-collect')
            ->expectsOutputToContain('Session garbage collector ran successfully.')
            ->assertSuccessful();
    }
}
