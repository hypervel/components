<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\Reverb\ReverbServiceProvider;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Hypervel\WebSocketServer\Sender;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class GracefulShutdownTest extends ReverbTestCase
{
    protected function tearDown(): void
    {
        WebSocketHandler::flushState();

        parent::tearDown();
    }

    // ── Drain connections ─────────────────────────────────────────────

    public function testDrainConnectionsCallsCloseForEachConnection()
    {
        $connectionA = $this->createReverbConnection();
        $connectionB = $this->createReverbConnection();

        $this->addToWebSocketHandler(1, $connectionA);
        $this->addToWebSocketHandler(2, $connectionB);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $provider->drainConnections();

        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testDrainConnectionsRemovesFromRegistryBeforeClose()
    {
        $connection = $this->createReverbConnection();
        $this->addToWebSocketHandler(1, $connection);

        // If takeConnection works, onClose would find nothing
        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $provider->drainConnections();

        // Verify the connection is gone — a second take returns null
        $this->assertNull(WebSocketHandler::takeConnection(1));
    }

    public function testDrainConnectionsReleasesConnectionSlots()
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

    public function testDrainConnectionsHandlesEmptyConnectionList()
    {
        $this->assertEmpty(WebSocketHandler::connections());

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $provider->drainConnections();

        $this->assertEmpty(WebSocketHandler::connections());
    }

    // ── Server::close slot flag ───────────────────────────────────────

    public function testServerCloseNowClearsSlotFlag()
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

    public function testFlushWebhookBuffersSchedulesFlushJob()
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

    public function testFlushWebhookBuffersSkipsWhenNoBatching()
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        // Default config has no batching
        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'flushWebhookBuffers');
        $method->invoke($provider);

        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    public function testFlushWebhookBuffersSkipsWhenBufferEmpty()
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

    public function testTakeConnectionReturnsAndRemovesConnection()
    {
        $connection = $this->createReverbConnection();
        $this->addToWebSocketHandler(42, $connection);

        $taken = WebSocketHandler::takeConnection(42);

        $this->assertSame($connection, $taken);
        $this->assertEmpty(WebSocketHandler::connections());
    }

    public function testTakeConnectionReturnsNullWhenAlreadyTaken()
    {
        $connection = $this->createReverbConnection();
        $this->addToWebSocketHandler(42, $connection);

        WebSocketHandler::takeConnection(42);
        $second = WebSocketHandler::takeConnection(42);

        $this->assertNull($second);
    }

    // ── Scaling subscriber ────────────────────────────────────────────

    public function testDisconnectScalingSubscriberCallsDisconnect()
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

    public function testDisconnectScalingSubscriberSkipsWhenNotScaling()
    {
        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->shouldNotReceive('disconnect');
        $this->app->instance(PubSubProvider::class, $pubSub);

        $provider = $this->app->getProvider(ReverbServiceProvider::class);
        $method = new ReflectionMethod($provider, 'disconnectScalingSubscriber');
        $method->invoke($provider);
    }

    // ── Close code plumbing ───────────────────────────────────────────

    public function testDisconnectWithNoCodeUsesPlainPath()
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('disconnect')->once()->with(99)->andReturn(true);

        $wsConnection = new \Hypervel\Reverb\Servers\Hypervel\Connection($sender, 99);
        $wsConnection->close();
    }

    public function testDisconnectWithCodeForwardsCodeAndReason()
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('disconnect')->once()->with(99, 1001, 'Server restarting')->andReturn(true);

        $wsConnection = new \Hypervel\Reverb\Servers\Hypervel\Connection($sender, 99);
        $wsConnection->close(code: 1001, reason: 'Server restarting');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    protected function createReverbConnection(): \Hypervel\Reverb\Connection
    {
        $sender = m::mock(Sender::class);
        $sender->shouldReceive('push')->zeroOrMoreTimes();
        $sender->shouldReceive('disconnect')->zeroOrMoreTimes()->andReturn(true);

        $wsConnection = new \Hypervel\Reverb\Servers\Hypervel\Connection($sender, rand(1, 99999));
        $app = $this->app->make(\Hypervel\Reverb\Contracts\ApplicationProvider::class)->all()->first();

        return new \Hypervel\Reverb\Connection($wsConnection, $app, null);
    }

    protected function addToWebSocketHandler(int $fd, \Hypervel\Reverb\Connection $connection): void
    {
        $reflection = new ReflectionProperty(WebSocketHandler::class, 'connections');
        $connections = $reflection->getValue();
        $connections[$fd] = $connection;
        $reflection->setValue(null, $connections);
    }
}
