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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use stdClass;
use Swoole\Server;

class WebSocketServerServiceProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testResolvesPipeListenerOnlyForWebSocketSenderMessages(): void
    {
        $registeredListener = null;

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')
            ->with(OnPipeMessage::class, Argument::type(Closure::class))
            ->resolves(function (string $event, Closure $listener) use (&$registeredListener): void {
                $registeredListener = $listener;
            });

        $message = new SenderPipeMessage('disconnect', [42]);
        $listener = Double::for(OnPipeMessageListener::class)->strict();
        $listener->expects('handle')->with($message);

        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')->with('events')->returns($events);
        $application->expects('make')->with(OnPipeMessageListener::class)->returns($listener);

        (new WebSocketServerServiceProvider($application->instance()))->boot();

        $server = Double::for(Server::class)->strict();
        $registeredListener(new OnPipeMessage($server, 0, new stdClass));
        $registeredListener(new OnPipeMessage($server, 0, $message));
    }
}
