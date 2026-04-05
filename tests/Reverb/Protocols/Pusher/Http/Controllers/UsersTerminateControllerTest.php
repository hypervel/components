<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class UsersTerminateControllerTest extends ReverbTestCase
{
    public function testReturns404ForNonMatchingRoute()
    {
        // Hitting an invalid path that doesn't match the route pattern
        $response = $this->signedPostRequest('channels/users/not-a-user/terminate_connections');

        $response->assertStatus(404);
    }

    public function testTerminatesMatchingUserConnections()
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

    public function testReturns200WhenUserHasNoConnections()
    {
        $response = $this->signedPostRequest('users/nonexistent-user/terminate_connections');

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());
    }

    public function testPublishesTerminateViaPubsubWhenScalingEnabled()
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

        $response = $this->signedPostRequest('users/456/terminate_connections');

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());
    }

    public function testFailsWhenUsingAnInvalidSignature()
    {
        $response = $this->reverbCall('POST', '/apps/123456/users/987/terminate_connections', [
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $response->assertStatus(401);
    }
}
