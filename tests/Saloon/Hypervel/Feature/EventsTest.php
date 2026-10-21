<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Hypervel\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Events\SendingSaloonRequest;
use Hypervel\Saloon\Events\SentSaloonRequest;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class EventsTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testEventsAreFiredWhenARequestIsBeingSentAndWhenARequestHasBeenSent(): void
    {
        Saloon::fake([
            new MockResponse(['name' => 'Sam'], 200),
        ]);

        Event::fake();

        $response = TestConnector::make()->send(new UserRequest);

        Event::assertDispatched(SendingSaloonRequest::class, function (SendingSaloonRequest $event) use ($response): bool {
            return $response->pendingRequest() === $event->pendingRequest;
        });

        Event::assertDispatched(SentSaloonRequest::class, function (SentSaloonRequest $event) use ($response): bool {
            return $response === $event->response && $response->pendingRequest() === $event->pendingRequest;
        });
    }

    public function testAScopedEventFakeIsRemovedFromTheResolvedManagerAfterwards(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Alex']),
            MockResponse::make(['name' => 'Taylor']),
        ]);
        $received = [];
        Event::listen(SentSaloonRequest::class, function (SentSaloonRequest $event) use (&$received): void {
            $received[] = $event->response->json('name');
        });
        $connector = new TestConnector;

        $connector->send(new UserRequest);

        Event::fakeFor(function () use ($connector): void {
            $response = $connector->send(new UserRequest);

            Event::assertDispatched(
                SentSaloonRequest::class,
                fn (SentSaloonRequest $event): bool => $event->response === $response,
            );
        });

        $connector->send(new UserRequest);

        $this->assertSame(['Sam', 'Taylor'], $received);
    }
}
