<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class ChannelUsersControllerTest extends ReverbTestCase
{
    public function testReturnsErrorWhenNonPresenceChannelProvided(): void
    {
        $this->subscribeConnection('test-channel');

        $response = $this->signedRequest('channels/test-channel/users');

        $response->assertStatus(400);
    }

    public function testReturnsErrorWhenUnoccupiedChannelProvided(): void
    {
        $response = $this->signedRequest('channels/presence-test-channel/users');

        $response->assertStatus(404);
    }

    public function testReturnsTheUserData(): void
    {
        $channel = $this->channels()->findOrCreate('presence-test-channel');

        $connection = new FakeConnection('test-connection-one');
        $data = json_encode(['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $connection = new FakeConnection('test-connection-two');
        $data = json_encode(['user_id' => 2, 'user_info' => ['name' => 'Joe']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $connection = new FakeConnection('test-connection-three');
        $data = json_encode(['user_id' => 3, 'user_info' => ['name' => 'Jess']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $response = $this->signedRequest('channels/presence-test-channel/users');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $body['users']);
    }

    public function testReturnsUniqueUserData(): void
    {
        $channel = $this->channels()->findOrCreate('presence-test-channel');

        $connection = new FakeConnection('test-connection-one');
        $data = json_encode(['user_id' => 3, 'user_info' => ['name' => 'Taylor']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $connection = new FakeConnection('test-connection-two');
        $data = json_encode(['user_id' => 2, 'user_info' => ['name' => 'Joe']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $connection = new FakeConnection('test-connection-three');
        $data = json_encode(['user_id' => 3, 'user_info' => ['name' => 'Jess']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $response = $this->signedRequest('channels/presence-test-channel/users');

        $response->assertStatus(200);

        $body = $response->json();
        // user_id 3 appears twice but should be deduplicated
        $this->assertSame([['id' => 3], ['id' => 2]], $body['users']);
    }

    public function testReturnsUsersWhenThePresenceChannelExistsOnlyOnAnotherWorker(): void
    {
        $metrics = m::mock(MetricsHandler::class);
        $metrics->expects('gather')
            ->with(
                m::type(Application::class),
                'presence',
                ['channel' => 'presence-remote-channel'],
            )
            ->andReturn([
                'exists' => true,
                'presence' => true,
                'users' => [
                    ['user_id' => 'remote-user', 'user_info' => ['name' => 'Taylor']],
                ],
            ]);
        $this->app->instance(MetricsHandler::class, $metrics);

        $response = $this->signedRequest('channels/presence-remote-channel/users');

        $response->assertStatus(200);
        $this->assertSame([['id' => 'remote-user']], $response->json('users'));
    }

    public function testFailsWhenUsingAnInvalidSignature(): void
    {
        $response = $this->reverbGet('/apps/123456/channels/presence-test-channel/users');

        $response->assertStatus(401);
    }
}
