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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Swoole\Server;

class HttpServiceProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testBeforeForkClearsResolvedConnectionHandlers(): void
    {
        $listeners = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')
            ->resolves(function (string $event, callable $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $factory = Double::for(Factory::class)->strict();
        $factory->expects('forgetConnectionHandlers');
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')->with('events')->returns($events);
        $application->expects('resolved')->with(Factory::class)->returns(true);
        $application->expects('make')->with(Factory::class)->returns($factory);

        (new HttpServiceProvider($application->instance()))->boot();

        $listeners[BeforeServerFork::class](new BeforeServerFork(Double::for(Server::class)->strict()));
    }

    public function testBeforeForkDoesNotResolveAnUnusedFactory(): void
    {
        $listeners = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')
            ->resolves(function (string $event, callable $listener) use (&$listeners): void {
                $listeners[$event] = $listener;
            });
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')->with('events')->returns($events);
        $application->expects('resolved')->with(Factory::class)->returns(false);

        (new HttpServiceProvider($application->instance()))->boot();

        $listeners[BeforeServerFork::class](new BeforeServerFork(Double::for(Server::class)->strict()));
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
