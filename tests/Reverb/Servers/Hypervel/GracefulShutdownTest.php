<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\Reverb\ReverbServiceProvider;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Reverb\Webhooks\DeferredWebhookManager;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Hypervel\WebSocketServer\Sender;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Swoole\Server;
use Throwable;

class GracefulShutdownTest extends ReverbTestCase
{
    protected function tearDown(): void
    {
        WebSocketHandler::flushState();

        parent::tearDown();
    }

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
        $firstSender = m::mock(Sender::class);
        $firstSender->expects('disconnect')->andThrow($firstFailure);
        $secondSender = m::mock(Sender::class);
        $secondSender->expects('disconnect')->with(2, 1001, 'Server restarting')->andReturnTrue();

        $this->addToWebSocketHandler(1, $this->createReverbConnection($firstSender, 1));
        $this->addToWebSocketHandler(2, $this->createReverbConnection($secondSender, 2));

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
        $events = m::mock(Dispatcher::class);
        $listener = null;
        $events->expects('listen')
            ->with(OnWorkerExit::class, m::on(function (callable $callback) use (&$listener): bool {
                $listener = $callback;

                return true;
            }));
        $this->app->instance('events', $events);
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->expects('report')->with($failures['drain']);
        $exceptionHandler->expects('report')->with($failures['subscriber']);
        $exceptionHandler->expects('report')->with($failures['webhooks']);
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);
        $provider = new GracefulShutdownServiceProviderProbe($this->app);
        $provider->failures = $failures;
        $provider->registerShutdownHandlerForTest();
        $server = m::mock(Server::class);
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
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->expects('report')
            ->with($failure)
            ->andThrow(new RuntimeException('Reporting failed.'));
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

    public function testFlushWebhookBuffersSchedulesFlushJob(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => ['enabled' => true],
        ]);

        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock')->once();
        $buffer->shouldReceive('hasRemaining')->andReturn(true);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'flushWebhookBuffers');
        $method->invoke($provider);

        Queue::assertPushed(FlushWebhookBatchJob::class);
    }

    public function testFlushWebhookBuffersSkipsWhenNoBatching(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        // Default config has no batching
        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'flushWebhookBuffers');
        $method->invoke($provider);

        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    public function testFlushWebhookBuffersSkipsWhenBufferEmpty(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => ['enabled' => true],
        ]);

        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('clearFlushLock')->once();
        $buffer->shouldReceive('hasRemaining')->andReturn(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'flushWebhookBuffers');
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

        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('getSubscriptionCount')
            ->with('test-app', 'test-channel')
            ->andReturn(0);
        $this->app->instance(SharedState::class, $sharedState);

        $app = new Application(
            'test-app',
            'test-key',
            'test-secret',
            60,
            30,
            ['*'],
            10_000,
            webhooks: ['url' => 'https://example.com/webhook', 'events' => ['channel_vacated']],
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

    public function testDisconnectScalingSubscriberCallsDisconnect(): void
    {
        $this->app['config']->set('reverb.servers.reverb.scaling.enabled', true);

        $provider = new \Hypervel\Reverb\Servers\Hypervel\HypervelServerProvider(
            $this->app,
            $this->app['config']->get('reverb.servers.reverb', [])
        );
        $provider->register();
        $this->app->make(ServerProviderManager::class)->withPublishing();

        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->shouldReceive('disconnect')->once();
        $this->app->instance(PubSubProvider::class, $pubSub);

        $reverbProvider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($reverbProvider, 'disconnectScalingSubscriber');
        $method->invoke($reverbProvider);
    }

    public function testDisconnectScalingSubscriberSkipsWhenNotScaling(): void
    {
        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->shouldNotReceive('disconnect');
        $this->app->instance(PubSubProvider::class, $pubSub);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'disconnectScalingSubscriber');
        $method->invoke($provider);
    }

    // ── Close code plumbing ───────────────────────────────────────────

    public function testDisconnectWithNoCodeUsesPlainPath(): void
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('disconnect')->once()->with(99)->andReturn(true);

        $wsConnection = new \Hypervel\Reverb\Servers\Hypervel\Connection($sender, 99);
        $wsConnection->close();
    }

    public function testDisconnectWithCodeForwardsCodeAndReason(): void
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('disconnect')->once()->with(99, 1001, 'Server restarting')->andReturn(true);

        $wsConnection = new \Hypervel\Reverb\Servers\Hypervel\Connection($sender, 99);
        $wsConnection->close(code: 1001, reason: 'Server restarting');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    protected function createReverbConnection(?Sender $sender = null, ?int $fd = null): \Hypervel\Reverb\Connection
    {
        if ($sender === null) {
            $sender = m::mock(Sender::class);
            $sender->shouldReceive('push')->zeroOrMoreTimes();
            $sender->shouldReceive('disconnect')->zeroOrMoreTimes()->andReturn(true);
        }

        $wsConnection = new \Hypervel\Reverb\Servers\Hypervel\Connection($sender, $fd ?? rand(1, 99999));
        $app = $this->app->make(\Hypervel\Reverb\Contracts\ApplicationProvider::class)->all()->first();

        return new \Hypervel\Reverb\Connection($wsConnection, $app, null);
    }

    protected function addToWebSocketHandler(int $fd, \Hypervel\Reverb\Connection $connection): void
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

    public function registerShutdownHandlerForTest(): void
    {
        $this->registerShutdownHandler();
    }

    public function reportShutdownFailureForTest(Throwable $throwable): void
    {
        $this->reportShutdownFailure($throwable);
    }

    public function drainConnections(): void
    {
        $this->fail('drain');
    }

    protected function disconnectScalingSubscriber(): void
    {
        $this->fail('subscriber');
    }

    protected function flushWebhookBuffers(): void
    {
        $this->fail('webhooks');
    }

    private function fail(string $operation): void
    {
        $this->operations[] = $operation;

        if (isset($this->failures[$operation])) {
            throw $this->failures[$operation];
        }
    }
}
