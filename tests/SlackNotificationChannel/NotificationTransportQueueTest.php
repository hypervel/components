<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\Events\NotificationDelivered;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Notifications\Slack\SlackRoute;
use Hypervel\Notifications\SlackChannelServiceProvider;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;
use Throwable;

class NotificationTransportQueueTest extends TestCase
{
    /**
     * Register the Slack channel package.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [SlackChannelServiceProvider::class];
    }

    /**
     * Use the real queue that serializes and executes listener jobs.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('queue.default', 'sync');
    }

    #[DataProvider('transports')]
    public function testDeliveredAndSentListenersReceiveNormalObjectsThroughTheRealQueue(bool $webhook): void
    {
        $this->configureTransport($webhook, 'success');
        $events = $this->app->make(Dispatcher::class);
        $live = [];
        foreach ([NotificationDelivered::class, NotificationSent::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$live): void {
                $live[$event::class] = $event;
            });
            $events->listen($eventClass, NotificationTransportQueuedListener::class);
        }

        $this->app->make(ChannelManager::class)->sendNow(
            new NotificationTransportNotifiable($webhook),
            new NotificationTransportSlackNotification,
            ['slack'],
        );

        $received = $this->app->make(NotificationTransportQueuedListener::class)->received;
        $this->assertCount(2, $received);
        $this->assertSame(NotificationDelivered::class, $received[0]::class);
        $this->assertSame(NotificationSent::class, $received[1]::class);

        foreach ($received as $event) {
            $this->assertSame($live[$event::class]->response::class, $event->response::class);
            $this->assertNotSame($live[$event::class]->response, $event->response);
            $this->assertSame('slack', $event->channel);
            if ($webhook) {
                $this->assertInstanceOf(ResponseInterface::class, $event->response);
                $this->assertSame('ok', (string) $event->response->getBody());
            } else {
                $this->assertInstanceOf(Response::class, $event->response);
                $this->assertSame('fixture', $event->response->json('ts'));
            }
        }
    }

    /**
     * Provide both Slack transports.
     */
    public static function transports(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('transportFailures')]
    public function testFailedListenersKeepExceptionApisAndDoNotMaskTheOriginalFailure(bool $webhook, string $kind): void
    {
        $this->configureTransport($webhook, $kind);
        $events = $this->app->make(Dispatcher::class);
        $live = null;
        $events->listen(NotificationFailed::class, function (NotificationFailed $event) use (&$live): void {
            $live = $event->data['exception'];
        });
        $events->listen(NotificationFailed::class, NotificationTransportQueuedListener::class);

        $thrown = null;
        try {
            $this->app->make(ChannelManager::class)->sendNow(
                new NotificationTransportNotifiable($webhook),
                new NotificationTransportSlackNotification,
                ['slack'],
            );
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(Throwable::class, $thrown);
        $this->assertSame($live, $thrown);
        $received = $this->app->make(NotificationTransportQueuedListener::class)->received;
        $this->assertCount(1, $received);
        $restored = $received[0]->data['exception'];
        $this->assertSame($thrown::class, $restored::class);
        $this->assertNotSame($thrown, $restored);
        $this->assertSame($thrown->getMessage(), $restored->getMessage());

        if ($kind === 'HTTP failure') {
            if ($webhook) {
                $this->assertInstanceOf(GuzzleRequestException::class, $restored);
                $this->assertSame(429, $restored->getResponse()->getStatusCode());
            } else {
                $this->assertInstanceOf(RequestException::class, $restored);
                $this->assertSame(429, $restored->response->status());
            }
        } else {
            $transport = $restored;
            if (! $webhook) {
                $this->assertInstanceOf(ConnectionException::class, $restored);
                $transport = $restored->getPrevious();
            }
            $this->assertInstanceOf(ConnectException::class, $transport);
            $this->assertStringContainsString('hello', (string) $transport->getRequest()->getBody());
        }
    }

    /**
     * Provide HTTP and connection failures from both Slack transports.
     */
    public static function transportFailures(): array
    {
        return [[false, 'HTTP failure'], [true, 'HTTP failure'], [false, 'connection failure'], [true, 'connection failure']];
    }

    public function testAnEarlierSynchronousObserverCanCloseTheResponseBeforeQueueing(): void
    {
        $this->configureTransport(false, 'success');
        $events = $this->app->make(Dispatcher::class);
        $events->listen(NotificationSent::class, static function (NotificationSent $event): void {
            $event->response->close();
        });
        $events->listen(NotificationSent::class, NotificationTransportQueuedListener::class);

        $this->app->make(ChannelManager::class)->sendNow(
            new NotificationTransportNotifiable(false),
            new NotificationTransportSlackNotification,
            ['slack'],
        );

        $received = $this->app->make(NotificationTransportQueuedListener::class)->received;
        $this->assertCount(1, $received);
        $this->assertInstanceOf(Response::class, $received[0]->response);
        $this->assertFalse($received[0]->response->toPsrResponse()->getBody()->isReadable());
    }

    public function testDecliningQueueingDoesNotInspectAnUnserializableBody(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $response = new Response(new PsrResponse(200, [], new NoSeekStream(Utils::streamFor('unread'))));
        $events->listen(NotificationSent::class, NotificationTransportDecliningListener::class);
        $events->dispatch(new NotificationSent(new stdClass, new Notification, 'custom', $response));

        $this->assertSame([], $this->app->make(NotificationTransportDecliningListener::class)->received);
        $this->assertSame(0, $response->toPsrResponse()->getBody()->tell());
    }

    public function testExistingSymfonyMailFailureConversionRemainsUnchanged(): void
    {
        $response = m::mock(SymfonyResponse::class);
        $original = new HttpTransportException('Mailer HTTP failure', $response);
        $manager = $this->app->make(ChannelManager::class);
        $manager->extend('test-mail', fn (): NotificationTransportFailingChannel => new NotificationTransportFailingChannel($original));
        $events = $this->app->make(Dispatcher::class);
        $live = null;
        $events->listen(NotificationFailed::class, function (NotificationFailed $event) use (&$live): void {
            $live = $event->data['exception'];
        });
        $events->listen(NotificationFailed::class, NotificationTransportQueuedListener::class);

        $thrown = null;
        try {
            $manager->sendNow(new stdClass, new Notification, ['test-mail']);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertSame($original, $thrown);
        $this->assertSame(TransportException::class, $live::class);
        $received = $this->app->make(NotificationTransportQueuedListener::class)->received;
        $this->assertCount(1, $received);
        $this->assertSame(TransportException::class, $received[0]->data['exception']::class);
        $this->assertSame($live->getMessage(), $received[0]->data['exception']->getMessage());
    }

    /**
     * Configure the real channel with a local transport response or failure.
     */
    private function configureTransport(bool $webhook, string $kind): void
    {
        if ($webhook) {
            $result = match ($kind) {
                'success' => new PsrResponse(200, [], 'ok'),
                'HTTP failure' => new PsrResponse(429, [], 'rate limited'),
                'connection failure' => new ConnectException('Connection refused', new Request('POST', 'https://hooks.slack.test/fixture', [], '{"text":"hello"}')),
            };
            $client = new Client(['handler' => HandlerStack::create(new MockHandler([$result]))]);
            $this->app->instance(SlackWebhookChannel::class, new SlackWebhookChannel($client));
        } else {
            $factory = $this->app->make(Factory::class);
            $result = match ($kind) {
                'success' => Factory::response(['ok' => true, 'ts' => 'fixture']),
                'HTTP failure' => Factory::response(['ok' => false, 'error' => 'ratelimited'], 429),
                'connection failure' => Factory::failedConnection('Connection refused'),
            };
            $factory->fake(['slack.com/api/*' => $result]);
        }
    }
}

class NotificationTransportQueuedListener implements ShouldQueue
{
    /**
     * The events received through the queue.
     */
    public array $received = [];

    /**
     * Record the restored event received through the queue.
     */
    public function handle(NotificationSent|NotificationDelivered|NotificationFailed $event): void
    {
        $this->received[] = $event;
    }
}

class NotificationTransportDecliningListener extends NotificationTransportQueuedListener
{
    /**
     * Decline queueing this event.
     */
    public function shouldQueue(NotificationSent $event): bool
    {
        return false;
    }
}

class NotificationTransportNotifiable
{
    /**
     * Select the Slack transport for this recipient.
     */
    public function __construct(private readonly bool $webhook)
    {
    }

    /**
     * Return the notification route.
     */
    public function routeNotificationFor(string $driver, Notification $notification): string|SlackRoute
    {
        return $this->webhook ? 'https://hooks.slack.test/fixture' : new SlackRoute('#alerts', 'fixture-token');
    }
}

class NotificationTransportSlackNotification extends Notification
{
    /**
     * Build the Slack message.
     */
    public function toSlack(mixed $notifiable): SlackMessage
    {
        return (new SlackMessage)->text('hello');
    }
}

class NotificationTransportFailingChannel
{
    /**
     * Create a channel with a known delivery failure.
     */
    public function __construct(private readonly Throwable $exception)
    {
    }

    /**
     * Throw the original delivery failure.
     */
    public function send(mixed $notifiable, Notification $notification): never
    {
        throw $this->exception;
    }
}
