<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Http\Client\Events\ResponseReceived;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\HttpServiceProvider;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Swoole\Server;

class HttpServiceProviderTest extends TestCase
{
    public function testBeforeForkClearsResolvedConnectionHandlers(): void
    {
        $listeners = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->once()
            ->andReturnUsing(function (string $event, callable $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('forgetConnectionHandlers')->once()->andReturnSelf();
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with('events')->andReturn($events);
        $application->shouldReceive('resolved')->once()->with(Factory::class)->andReturnTrue();
        $application->shouldReceive('make')->once()->with(Factory::class)->andReturn($factory);

        (new HttpServiceProvider($application))->boot();

        $listeners[BeforeServerFork::class](new BeforeServerFork(m::mock(Server::class)));
    }

    public function testBeforeForkDoesNotResolveAnUnusedFactory(): void
    {
        $listeners = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->once()
            ->andReturnUsing(function (string $event, callable $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with('events')->andReturn($events);
        $application->shouldReceive('resolved')->once()->with(Factory::class)->andReturnFalse();

        (new HttpServiceProvider($application))->boot();

        $listeners[BeforeServerFork::class](new BeforeServerFork(m::mock(Server::class)));
    }

    public function testAScopedEventFakeReachesTheResolvedFactoryAndIsRemovedAfterwards(): void
    {
        Http::fake(['*' => Http::response()]);
        $received = [];
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$received): void {
            $received[] = $event->request->url();
        });

        Http::get('https://example.com/before');

        Event::fakeFor(function (): void {
            Http::get('https://example.com/inside');

            Event::assertDispatched(
                ResponseReceived::class,
                fn (ResponseReceived $event): bool => $event->request->url() === 'https://example.com/inside',
            );
        });

        Http::get('https://example.com/after');

        $this->assertSame(['https://example.com/before', 'https://example.com/after'], $received);
    }
}
