<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\TerminateUserPipeMessage;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use Swoole\Server;

class UsersTerminateControllerTest extends ReverbTestCase
{
    public function testReturns404ForNonMatchingRoute(): void
    {
        // Hitting an invalid path that doesn't match the route pattern
        $response = $this->signedPostRequest('channels/users/not-a-user/terminate_connections');

        $response->assertStatus(404);
    }

    public function testTerminatesMatchingUserConnections(): void
    {
        $channel = $this->channels()->findOrCreate('presence-test-channel-one');

        $connectionOne = new FakeConnection('test-connection-one');
        $data = json_encode(['user_id' => '123', 'user_info' => ['name' => 'Taylor']]);
        $channel->subscribe($connectionOne, static::validAuth($connectionOne->id(), 'presence-test-channel-one', $data), $data);

        $connectionTwo = new FakeConnection('test-connection-two');
        $data = json_encode(['user_id' => '456', 'user_info' => ['name' => 'Joe']]);
        $channel->subscribe($connectionTwo, static::validAuth($connectionTwo->id(), 'presence-test-channel-one', $data), $data);

        // Also subscribe both to a non-presence channel
        $channelTwo = $this->channels()->findOrCreate('test-channel-two');
        $channelTwo->subscribe($connectionOne);
        $channelTwo->subscribe($connectionTwo);

        $this->assertCount(2, $channel->connections());
        $this->assertCount(2, $channelTwo->connections());

        $response = $this->signedPostRequest('users/456/terminate_connections');

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());

        // Connection with user_id 456 should have been terminated
        $connectionTwo->assertHasBeenTerminated();
        // Connection with user_id 123 should NOT have been terminated
        $this->assertFalse($connectionOne->wasTerminated);
    }

    public function testReturns200WhenUserHasNoConnections(): void
    {
        $response = $this->signedPostRequest('users/nonexistent-user/terminate_connections');

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());
    }

    public function testTerminatesMatchingUserConnectionsOnEverySiblingWorker(): void
    {
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 3];
        $server->worker_id = 1;
        $server->expects('sendMessage')
            ->with(m::on(fn (TerminateUserPipeMessage $message): bool => $message->appId === '123456'
                && $message->userId === '456'), 0)
            ->andReturnTrue();
        $server->expects('sendMessage')
            ->with(m::type(TerminateUserPipeMessage::class), 2)
            ->andReturnTrue();
        $this->app->instance(Server::class, $server);

        $response = $this->signedPostRequest('users/456/terminate_connections');

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());
    }

    public function testRejectedSiblingTerminationAttemptsEveryWorkerAndReturnsAnError(): void
    {
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 3];
        $server->worker_id = 0;
        $server->expects('sendMessage')
            ->with(m::type(TerminateUserPipeMessage::class), 1)
            ->andReturnFalse();
        $server->expects('sendMessage')
            ->with(m::type(TerminateUserPipeMessage::class), 2)
            ->andReturnTrue();
        $this->app->instance(Server::class, $server);

        $response = $this->signedPostRequest('users/456/terminate_connections');

        $response->assertStatus(500);
    }

    public function testPublishesTerminateViaPubsubWhenScalingEnabled(): void
    {
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->shouldReceive('subscribesToEvents')->andReturn(true);
        $this->app->instance(ServerProviderManager::class, $serverManager);

        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->shouldReceive('publish')->once()->with(m::on(function (array $payload) {
            return $payload['type'] === 'terminate'
                && $payload['app_id'] === '123456'
                && $payload['user_id'] === '456';
        }));
        $this->app->instance(PubSubProvider::class, $pubSub);
        $server = m::mock(Server::class);
        $server->shouldNotReceive('sendMessage');
        $this->app->instance(Server::class, $server);

        $response = $this->signedPostRequest('users/456/terminate_connections');

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());
    }

    public function testFailsWhenUsingAnInvalidSignature(): void
    {
        $response = $this->reverbCall('POST', '/apps/123456/users/987/terminate_connections', [
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $response->assertStatus(401);
    }
}
