<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Routing\Events\PreparingResponse;
use Hypervel\Routing\Events\ResponsePrepared;
use Hypervel\Routing\Events\RouteMatched;
use Hypervel\Routing\Events\Routing;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Route;

/**
 * Tests that routing events are only dispatched when listeners are registered.
 *
 * The Router wraps each event dispatch in a hasListeners() guard so that event
 * object construction and dispatch are skipped entirely when nobody is listening.
 * This eliminates ~8us/request of overhead for apps that don't use routing events.
 *
 * @internal
 * @coversNothing
 */
class RouterEventGuardTest extends RoutingTestCase
{
    public function testNoRoutingEventsDispatchedWithoutListeners(): void
    {
        Route::get('/test', fn () => 'ok');

        Event::fake([Routing::class, RouteMatched::class, PreparingResponse::class, ResponsePrepared::class]);

        $this->get('/test');

        Event::assertNotDispatched(Routing::class);
        Event::assertNotDispatched(RouteMatched::class);
        Event::assertNotDispatched(PreparingResponse::class);
        Event::assertNotDispatched(ResponsePrepared::class);
    }

    public function testRoutingEventDispatchedWhenListenerRegistered(): void
    {
        $dispatched = false;
        Event::listen(Routing::class, function () use (&$dispatched) {
            $dispatched = true;
        });

        Route::get('/test', fn () => 'ok');

        $this->get('/test');

        $this->assertTrue($dispatched);
    }

    public function testRouteMatchedEventDispatchedWhenListenerRegistered(): void
    {
        $dispatched = false;
        Event::listen(RouteMatched::class, function () use (&$dispatched) {
            $dispatched = true;
        });

        Route::get('/test', fn () => 'ok');

        $this->get('/test');

        $this->assertTrue($dispatched);
    }

    public function testPreparingResponseEventDispatchedWhenListenerRegistered(): void
    {
        $dispatched = false;
        Event::listen(PreparingResponse::class, function () use (&$dispatched) {
            $dispatched = true;
        });

        Route::get('/test', fn () => 'ok');

        $this->get('/test');

        $this->assertTrue($dispatched);
    }

    public function testResponsePreparedEventDispatchedWhenListenerRegistered(): void
    {
        $dispatched = false;
        Event::listen(ResponsePrepared::class, function () use (&$dispatched) {
            $dispatched = true;
        });

        Route::get('/test', fn () => 'ok');

        $this->get('/test');

        $this->assertTrue($dispatched);
    }

    public function testEventsAreIndependentlyGuarded(): void
    {
        $routeMatchedDispatched = false;
        Event::listen(RouteMatched::class, function () use (&$routeMatchedDispatched) {
            $routeMatchedDispatched = true;
        });

        // Fake the other 3 events to verify they're NOT dispatched.
        Event::fake([Routing::class, PreparingResponse::class, ResponsePrepared::class]);

        // Re-register the RouteMatched listener after fake (fake clears listeners).
        Event::listen(RouteMatched::class, function () use (&$routeMatchedDispatched) {
            $routeMatchedDispatched = true;
        });

        Route::get('/test', fn () => 'ok');

        $this->get('/test');

        $this->assertTrue($routeMatchedDispatched);
        Event::assertNotDispatched(Routing::class);
        Event::assertNotDispatched(PreparingResponse::class);
        Event::assertNotDispatched(ResponsePrepared::class);
    }

    public function testAllEventsDispatchedWhenAllHaveListeners(): void
    {
        $dispatched = [];

        Event::listen(Routing::class, function () use (&$dispatched) {
            $dispatched[] = Routing::class;
        });
        Event::listen(RouteMatched::class, function () use (&$dispatched) {
            $dispatched[] = RouteMatched::class;
        });
        Event::listen(PreparingResponse::class, function () use (&$dispatched) {
            $dispatched[] = PreparingResponse::class;
        });
        Event::listen(ResponsePrepared::class, function () use (&$dispatched) {
            $dispatched[] = ResponsePrepared::class;
        });

        Route::get('/test', fn () => 'ok');

        $this->get('/test');

        $this->assertContains(Routing::class, $dispatched);
        $this->assertContains(RouteMatched::class, $dispatched);
        $this->assertContains(PreparingResponse::class, $dispatched);
        $this->assertContains(ResponsePrepared::class, $dispatched);
    }

    public function testWildcardListenerTriggersEventDispatch(): void
    {
        $dispatched = [];

        Event::listen('Hypervel\Routing\Events\*', function (string $event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        Route::get('/test', fn () => 'ok');

        $this->get('/test');

        $this->assertContains(Routing::class, $dispatched);
        $this->assertContains(RouteMatched::class, $dispatched);
        $this->assertContains(PreparingResponse::class, $dispatched);
        $this->assertContains(ResponsePrepared::class, $dispatched);
    }

    public function testRouteMatchedEventContainsCorrectData(): void
    {
        $capturedEvent = null;

        Event::listen(RouteMatched::class, function (RouteMatched $event) use (&$capturedEvent) {
            $capturedEvent = $event;
        });

        Route::get('/users/{id}', fn () => 'ok')->name('users.show');

        $this->get('/users/42');

        $this->assertNotNull($capturedEvent);
        $this->assertSame('users.show', $capturedEvent->route->getName());
        $this->assertSame('/users/42', $capturedEvent->request->getPathInfo());
    }
}
