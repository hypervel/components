<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Connection as ReverbConnection;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\Reverb\ReverbServiceProvider;
use Hypervel\Reverb\Servers\Hypervel\Connection as WebSocketConnection;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Reverb\Webhooks\DeferredWebhookManager;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Hypervel\WebSocketServer\Sender;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;
use Throwable;

class GracefulShutdownTest extends ReverbTestCase
{
    // ── Drain connections ─────────────────────────────────────────────

    public function testDrainConnectionsCallsCloseForEachConnection(): void
    {
        $connectionA = $this->createReverbConnection();
        $connectionB = $this->createReverbConnection();

        $this->addToWebSocketHandler(1, $connectionA);
        $this->addToWebSocketHandler(2, $connectionB);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $provider->drainConnections();

        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testDrainConnectionsRemovesFromRegistryBeforeClose(): void
    {
        $connection = $this->createReverbConnection();
        $this->addToWebSocketHandler(1, $connection);

        // If takeConnection works, onClose would find nothing
        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $provider->drainConnections();

        // Verify the connection is gone — a second take returns null
        $this->assertNull(WebSocketHandler::takeConnection(1));
    }

    public function testDrainConnectionsReleasesConnectionSlots(): void
    {
        $connection = $this->createReverbConnection();
        $connection->markConnectionSlotAcquired();
        $this->addToWebSocketHandler(1, $connection);

        $sharedState = $this->app->make(SharedState::class);
        $sharedState->acquireConnectionSlot($connection->app()->id(), 10);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $provider->drainConnections();

        $this->assertFalse($connection->hasAcquiredConnectionSlot());
    }

    public function testDrainConnectionsHandlesEmptyConnectionList(): void
    {
        $this->assertEmpty(WebSocketHandler::connections());

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $provider->drainConnections();

        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testDrainConnectionsAttemptsEveryConnectionAndPreservesTheFirstFailure(): void
    {
        $firstFailure = new RuntimeException('First disconnect failed.');
        $firstServer = Double::for(WebSocketServer::class)->strict();
        $firstServer->expects('disconnect')->throws($firstFailure);
        $secondServer = Double::for(WebSocketServer::class)->strict();
        $secondServer->expects('disconnect')->with(2, 1001, 'Server restarting')->returns(true);

        $this->addToWebSocketHandler(1, $this->createReverbConnection($this->createSender($firstServer), 1));
        $this->addToWebSocketHandler(2, $this->createReverbConnection($this->createSender($secondServer), 2));

        $provider = $this->app->getProvider(ReverbServiceProvider::class);

        try {
            $provider->drainConnections();
            $this->fail('Expected the connection drain to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstFailure, $exception);
        }

        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testShutdownAttemptsEveryPhaseAndReportsEachFailure(): void
    {
        $failures = [
            'drain' => new RuntimeException('Connection drain failed.'),
            'subscriber' => new RuntimeException('Subscriber disconnect failed.'),
            'webhooks' => new RuntimeException('Webhook flush failed.'),
        ];
        $events = Double::for(Dispatcher::class)->strict();
        $listener = null;
        $events->expects('listen')
            ->with(OnWorkerExit::class, Argument::capture($listener));
        $this->app->instance('events', $events);
        $exceptionHandler = Double::for(ExceptionHandler::class)->strict();
        $exceptionHandler->expects('report')->with(Argument::same($failures['drain']));
        $exceptionHandler->expects('report')->with(Argument::same($failures['subscriber']));
        $exceptionHandler->expects('report')->with(Argument::same($failures['webhooks']));
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);
        $provider = new GracefulShutdownServiceProviderProbe($this->app);
        $provider->failures = $failures;
        $provider->registerShutdownHandlerForTest();
        $server = Double::for(Server::class)->strict();
        $server->taskworker = false;

        $listener(new OnWorkerExit($server, 0));

        $this->assertSame(['drain', 'subscriber', 'webhooks'], $provider->operations);
    }

    public function testShutdownReportingFallsBackToThePhpErrorLog(): void
    {
        $directory = ParallelTesting::tempDir('ReverbShutdownReportingTest');
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $failure = new RuntimeException('Connection drain failed.');
        $exceptionHandler = Double::for(ExceptionHandler::class)->strict();
        $exceptionHandler->expects('report')
            ->with(Argument::same($failure))
            ->throws(new RuntimeException('Reporting failed.'));
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);
        $provider = new GracefulShutdownServiceProviderProbe($this->app);

        try {
            $provider->reportShutdownFailureForTest($failure);
            $contents = file_get_contents($errorLog);

            $this->assertIsString($contents);
            $this->assertStringContainsString('Connection drain failed.', $contents);
            $this->assertStringContainsString('Reporting failed.', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testShutdownReportingSkipsADirectCancellation(): void
    {
        $exceptionHandler = Double::for(ExceptionHandler::class)->strict();
        $exceptionHandler->expects('report')->never();
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);
        $provider = new GracefulShutdownServiceProviderProbe($this->app);

        $provider->reportShutdownFailureForTest(new CanceledException('Shutdown canceled.'));
    }

    // ── Server::close slot flag ───────────────────────────────────────

    public function testServerCloseNowClearsSlotFlag(): void
    {
        $connection = new FakeConnection;
        $connection->markConnectionSlotAcquired();

        $sharedState = $this->app->make(SharedState::class);
        $sharedState->acquireConnectionSlot($connection->app()->id(), 10);

        $server = $this->app->make(PusherServer::class);
        $server->close($connection);

        $this->assertFalse($connection->hasAcquiredConnectionSlot());
    }

    // ── Webhook flush ─────────────────────────────────────────────────

    public function testScheduleWebhookFlushesDispatchesWhenTheBufferGrantsIt(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $webhooks = $this->webhookConfig();
        $webhooks['url'] = 'https://example.com/webhook';
        $webhooks['events'] = ['channel_occupied'];
        $webhooks['batching']['enabled'] = true;
        config()->set('reverb.apps.apps.0.webhooks', $webhooks);

        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('shouldScheduleFlush')->returns(true);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'scheduleWebhookFlushes');
        $method->invoke($provider);

        Queue::assertPushed(FlushWebhookBatchJob::class);
    }

    public function testScheduleWebhookFlushesContinuesAfterOneApplicationsBufferFails(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $configuration = config()->array('reverb.apps.apps.0');
        $configuration['webhooks']['url'] = 'https://example.com/webhook';
        $configuration['webhooks']['batching']['enabled'] = true;
        config()->set('reverb.apps.apps', [
            array_replace($configuration, ['app_id' => 'unavailable']),
            array_replace($configuration, ['app_id' => 'healthy']),
        ]);

        $failure = new RuntimeException('Redis Cluster slot unavailable.');
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('shouldScheduleFlush')->with('unavailable')->throws($failure);
        $buffer->expects('shouldScheduleFlush')->with('healthy')->returns(true);
        $buffer->expects('clearFlushLock')->never();
        $this->app->instance(WebhookBatchBuffer::class, $buffer);
        $exceptionHandler = Double::for(ExceptionHandler::class)->strict();
        $exceptionHandler->expects('report')->with(Argument::same($failure));
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        (new ReflectionMethod($provider, 'scheduleWebhookFlushes'))->invoke($provider);

        Queue::assertPushed(FlushWebhookBatchJob::class, fn (FlushWebhookBatchJob $job): bool => $job->appId === 'healthy');
        Queue::assertPushed(FlushWebhookBatchJob::class, 1);
    }

    public function testScheduleWebhookFlushesSkipsWhenNoBatching(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        // Default config disables batching.
        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'scheduleWebhookFlushes');
        $method->invoke($provider);

        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    public function testScheduleWebhookFlushesSkipsWhenTheBufferRefuses(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $webhooks = $this->webhookConfig();
        $webhooks['url'] = 'https://example.com/webhook';
        $webhooks['events'] = ['channel_occupied'];
        $webhooks['batching']['enabled'] = true;
        config()->set('reverb.apps.apps.0.webhooks', $webhooks);

        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('shouldScheduleFlush')->returns(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'scheduleWebhookFlushes');
        $method->invoke($provider);

        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    // ── takeConnection ────────────────────────────────────────────────

    public function testTakeConnectionReturnsAndRemovesConnection(): void
    {
        $connection = $this->createReverbConnection();
        $this->addToWebSocketHandler(42, $connection);

        $taken = WebSocketHandler::takeConnection(42);

        $this->assertSame($connection, $taken?->connection());
        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testTakeConnectionReturnsNullWhenAlreadyTaken(): void
    {
        $connection = $this->createReverbConnection();
        $this->addToWebSocketHandler(42, $connection);

        WebSocketHandler::takeConnection(42);
        $second = WebSocketHandler::takeConnection(42);

        $this->assertNull($second);
    }

    // ── Deferred webhook preservation ──────────────────────────────────

    public function testShutdownDoesNotLosePreExistingDeferredWebhooks(): void
    {
        Queue::fake();

        $sharedState = Double::for(SharedState::class)->strict();
        $sharedState->allows('getSubscriptionCount')
            ->with('test-app', 'test-channel')
            ->returns(0);
        $sharedState->expects('clearSmoothingPending')->with('test-app', 'test-channel', 5000);
        $this->app->instance(SharedState::class, $sharedState);

        $webhooks = array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_vacated'],
        ]);

        $app = new Application(
            'test-app',
            'test-key',
            'test-secret',
            60,
            30,
            ['*'],
            10_000,
            webhooks: $webhooks,
        );

        $manager = $this->app->make(DeferredWebhookManager::class);

        // Defer a webhook with a short delay
        $manager->deferChannelVacated($app, 'test-channel', 0.05, 5000);

        // Simulate shutdown — setDraining should NOT cancel the pending timer
        $manager->setDraining(true);

        // Wait for the deferred timer to fire
        usleep(80_000);

        // The pre-existing deferred webhook should have fired
        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_vacated'
                && $job->payload->events[0]['channel'] === 'test-channel';
        });
    }

    // ── Scaling subscriber ────────────────────────────────────────────

    #[WithEnv('REVERB_SCALING_ENABLED', 'true')]
    public function testDisconnectScalingSubscriberCallsDisconnect(): void
    {
        $pubSub = Double::for(PubSubProvider::class)->strict();
        $pubSub->expects('disconnect');
        $this->app->instance(PubSubProvider::class, $pubSub);

        $reverbProvider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($reverbProvider, 'disconnectScalingSubscriber');
        $method->invoke($reverbProvider);
    }

    public function testDisconnectScalingSubscriberSkipsWhenNotScaling(): void
    {
        $pubSub = Double::for(PubSubProvider::class)->strict();
        $pubSub->expects('disconnect')->never();
        $this->app->instance(PubSubProvider::class, $pubSub);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'disconnectScalingSubscriber');
        $method->invoke($provider);
    }

    // ── Close code plumbing ───────────────────────────────────────────

    public function testDisconnectWithNoCodeUsesPlainPath(): void
    {
        $server = Double::for(WebSocketServer::class)->strict();
        $server->expects('disconnect')->with(99)->returns(true);

        $wsConnection = new WebSocketConnection($this->createSender($server), 99);
        $wsConnection->close();
    }

    public function testDisconnectWithCodeForwardsCodeAndReason(): void
    {
        $server = Double::for(WebSocketServer::class)->strict();
        $server->expects('disconnect')->with(99, 1001, 'Server restarting')->returns(true);

        $wsConnection = new WebSocketConnection($this->createSender($server), 99);
        $wsConnection->close(code: 1001, reason: 'Server restarting');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Create a Reverb connection with a controlled native transport.
     */
    protected function createReverbConnection(?Sender $sender = null, ?int $fd = null): ReverbConnection
    {
        if ($sender === null) {
            $server = Double::for(WebSocketServer::class)->strict();
            $server->allows('push')->returns(true);
            $server->allows('disconnect')->returns(true);
            $sender = $this->createSender($server);
        }

        $wsConnection = new WebSocketConnection($sender, $fd ?? rand(1, 99999));
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        return new ReverbConnection($wsConnection, $app, null);
    }

    /**
     * Create a real sender backed by an isolated native server.
     */
    protected function createSender(WebSocketServer $server): Sender
    {
        $server->allows('connection_info')->returns(['websocket_status' => WEBSOCKET_STATUS_ACTIVE]);
        $container = new Container;
        $container->instance(Server::class, $server);

        return new Sender($container);
    }

    /**
     * Register an established connection lifecycle.
     */
    protected function addToWebSocketHandler(int $fd, ReverbConnection $connection): void
    {
        $connection->markEstablished();
        $lifecycle = new ConnectionLifecycle($fd);
        $lifecycle->attach($connection);

        $reflection = new ReflectionProperty(WebSocketHandler::class, 'connections');
        $connections = $reflection->getValue();
        $connections[$fd] = $lifecycle;
        $reflection->setValue(null, $connections);
    }
}

class GracefulShutdownServiceProviderProbe extends ReverbServiceProvider
{
    /**
     * @var array<string, Throwable>
     */
    public array $failures = [];

    /**
     * @var list<string>
     */
    public array $operations = [];

    /**
     * Register the shutdown listener for the test.
     */
    public function registerShutdownHandlerForTest(): void
    {
        $this->registerShutdownHandler();
    }

    /**
     * Report a shutdown failure for the test.
     */
    public function reportShutdownFailureForTest(Throwable $throwable): void
    {
        $this->reportShutdownFailure($throwable);
    }

    /**
     * Record and exercise the connection drain.
     */
    public function drainConnections(): void
    {
        $this->fail('drain');
    }

    /**
     * Record and exercise the subscriber disconnect.
     */
    protected function disconnectScalingSubscriber(): void
    {
        $this->fail('subscriber');
    }

    /**
     * Record and exercise webhook scheduling.
     */
    protected function scheduleWebhookFlushes(): void
    {
        $this->fail('webhooks');
    }

    /**
     * Record the operation and throw its configured failure.
     */
    private function fail(string $operation): void
    {
        $this->operations[] = $operation;

        if (isset($this->failures[$operation])) {
            throw $this->failures[$operation];
        }
    }
}
