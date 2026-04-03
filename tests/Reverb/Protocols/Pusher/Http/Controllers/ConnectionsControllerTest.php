<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Tests\Reverb\ReverbTestCase;

/**
 * @internal
 * @coversNothing
 */
class ConnectionsControllerTest extends ReverbTestCase
{
    public function testCanReturnAConnectionCount()
    {
        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('presence-test-channel-two', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);

        $response = $this->signedRequest('connections');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('connections'));
    }

    public function testCanReturnTheCorrectConnectionCountWhenSubscribedToMultipleChannels()
    {
        // Same connection subscribed to two channels should count as 1
        $connection = $this->subscribeConnection('test-channel-one');

        // Subscribe same connection to another channel
        $channel = $this->channels()->findOrCreate('test-channel-two');
        $channel->subscribe($connection);

        $response = $this->signedRequest('connections');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('connections'));
    }

    public function testReturnsZeroConnectionsWhenNoneExist()
    {
        $response = $this->signedRequest('connections');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('connections'));
    }

    public function testFailsWhenUsingAnInvalidSignature()
    {
        $response = $this->reverbGet('/apps/123456/connections');

        $response->assertStatus(401);
    }
}
