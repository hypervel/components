<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Listeners\OnPipeMessageListener;
use Hypervel\WebSocketServer\SenderPipeMessage;
use Hypervel\WebSocketServer\WebSocketServerServiceProvider;
use Mockery as m;
use stdClass;
use Swoole\Server;

class WebSocketServerServiceProviderTest extends TestCase
{
    public function testResolvesPipeListenerOnlyForWebSocketSenderMessages(): void
    {
        $registeredListener = null;

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->once()
            ->with(OnPipeMessage::class, m::on(function (Closure $listener) use (&$registeredListener): bool {
                $registeredListener = $listener;

                return true;
            }));

        $message = new SenderPipeMessage('disconnect', [42]);
        $listener = m::mock(OnPipeMessageListener::class);
        $listener->shouldReceive('handle')->once()->with($message);

        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with('events')->andReturn($events);
        $application->shouldReceive('make')->once()->with(OnPipeMessageListener::class)->andReturn($listener);

        (new WebSocketServerServiceProvider($application))->boot();

        $server = m::mock(Server::class);
        $registeredListener(new OnPipeMessage($server, 0, new stdClass));
        $registeredListener(new OnPipeMessage($server, 0, $message));
    }
}
