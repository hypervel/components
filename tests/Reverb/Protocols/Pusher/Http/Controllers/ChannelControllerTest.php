<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Tests\Reverb\ReverbTestCase;

class ChannelControllerTest extends ReverbTestCase
{
    public function testCanReturnDataForASingleChannel()
    {
        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-one');

        $response = $this->signedRequest('channels/test-channel-one?info=user_count,subscription_count,cache');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertTrue($body['occupied']);
        $this->assertSame(2, $body['subscription_count']);
    }

    public function testReturnsUnoccupiedWhenNoConnections()
    {
        $response = $this->signedRequest('channels/test-channel-one?info=user_count,subscription_count,cache');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertFalse($body['occupied']);
        $this->assertArrayNotHasKey('subscription_count', $body);
        $this->assertArrayNotHasKey('user_count', $body);
    }

    public function testCanReturnCacheChannelAttributes()
    {
        $this->subscribeConnection('cache-test-channel-one');
        $this->channels()->find('cache-test-channel-one')->broadcast(['some' => 'data']);

        $response = $this->signedRequest('channels/cache-test-channel-one?info=subscription_count,cache');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['subscription_count']);
        $this->assertSame(['some' => 'data'], $body['cache']);
    }

    public function testCanReturnPresenceChannelAttributes()
    {
        $this->subscribeConnection('presence-test-channel-one', ['user_id' => 123, 'user_info' => ['name' => 'Taylor']]);
        $this->subscribeConnection('presence-test-channel-one', ['user_id' => 123, 'user_info' => ['name' => 'Taylor']]);

        $response = $this->signedRequest('channels/presence-test-channel-one?info=user_count,subscription_count,cache');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['user_count']);
        // Presence channels don't report subscription_count
        $this->assertArrayNotHasKey('subscription_count', $body);
    }

    public function testCanReturnOnlyTheRequestedAttributes()
    {
        $this->subscribeConnection('test-channel-one');

        // Request all info
        $response = $this->signedRequest('channels/test-channel-one?info=user_count,subscription_count,cache');
        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['subscription_count']);

        // Request only cache (non-cache channel has no cache)
        $response = $this->signedRequest('channels/test-channel-one?info=cache');
        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['occupied']);
        $this->assertArrayNotHasKey('subscription_count', $body);
        $this->assertArrayNotHasKey('cache', $body);

        // Request subscription_count and user_count
        $response = $this->signedRequest('channels/test-channel-one?info=subscription_count,user_count');
        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['subscription_count']);
    }

    public function testFailsWhenUsingAnInvalidSignature()
    {
        $response = $this->reverbGet('/apps/123456/channels/test-channel-one?info=user_count,subscription_count,cache');

        $response->assertStatus(401);
    }

    public function testAlwaysIncludesOccupiedStatus()
    {
        $this->subscribeConnection('test-channel-one');

        // Even without explicit info param, ChannelController appends 'occupied'
        $response = $this->signedRequest('channels/test-channel-one');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertTrue($body['occupied']);
    }

    public function testReturnsUserCountForPresenceChannel()
    {
        $this->subscribeConnection('presence-info-test', ['user_id' => 1, 'user_info' => ['name' => 'A']]);
        $this->subscribeConnection('presence-info-test', ['user_id' => 2, 'user_info' => ['name' => 'B']]);

        $response = $this->signedRequest('channels/presence-info-test?info=user_count');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame(2, $body['user_count']);
    }

    public function testReturnsUserCountForPresenceCacheChannel()
    {
        $this->subscribeConnection('presence-cache-info-test', ['user_id' => 1, 'user_info' => ['name' => 'A']]);

        $response = $this->signedRequest('channels/presence-cache-info-test?info=user_count');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame(1, $body['user_count']);
    }
}
