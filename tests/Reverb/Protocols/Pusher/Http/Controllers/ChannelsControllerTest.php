<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Tests\Reverb\ReverbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ChannelsControllerTest extends ReverbTestCase
{
    public function testCanReturnAllChannelInformation(): void
    {
        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('presence-test-channel-two', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);

        $response = $this->signedRequest('channels?info=user_count');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('channels', $body);
        $this->assertSame([], (array) $body['channels']['test-channel-one']);
        $this->assertSame(1, $body['channels']['presence-test-channel-two']['user_count']);
    }

    #[DataProvider('timestampOffsetsOutsideTolerance')]
    public function testRejectsRequestSignaturesOutsideTheTimestampTolerance(int $offset): void
    {
        $response = $this->signedRequest('channels', timestamp: time() + $offset);

        $response->assertStatus(401);
    }

    /**
     * Supply signature timestamp offsets outside the tolerance.
     */
    public static function timestampOffsetsOutsideTolerance(): array
    {
        return [
            'expired' => [-3600],
            'future' => [3600],
        ];
    }

    public function testRejectsRequestSignaturesWithoutATimestamp(): void
    {
        $query = 'auth_key=reverb-key&auth_version=1.0';
        $signature = hash_hmac('sha256', "GET\n/apps/123456/channels\n{$query}", 'reverb-secret');

        $response = $this->reverbGet("/apps/123456/channels?{$query}&auth_signature={$signature}");

        $response->assertStatus(401);
    }

    public function testCanReturnFilteredChannelsByPrefix(): void
    {
        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('presence-test-channel-two', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);

        $response = $this->signedRequest('channels?filter_by_prefix=presence-&info=user_count');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('channels', $body);
        $this->assertArrayNotHasKey('test-channel-one', $body['channels']);
        $this->assertSame(1, $body['channels']['presence-test-channel-two']['user_count']);
    }

    public function testReturnsEmptyResultsIfNoMetricsRequested(): void
    {
        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        $response = $this->signedRequest('channels');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('channels', $body);
        $this->assertSame([], (array) $body['channels']['test-channel-one']);
        $this->assertSame([], (array) $body['channels']['test-channel-two']);
    }

    public function testOnlyReturnsOccupiedChannels(): void
    {
        $connectionOne = $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        // Unsubscribe the connection from channel one
        $this->channels()->find('test-channel-one')->unsubscribe($connectionOne);

        $response = $this->signedRequest('channels');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('channels', $body);
        $this->assertArrayNotHasKey('test-channel-one', $body['channels']);
        $this->assertArrayHasKey('test-channel-two', $body['channels']);
    }

    public function testCanReturnSubscriptionCount(): void
    {
        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-one');

        $response = $this->signedRequest('channels?info=subscription_count');

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame(2, $body['channels']['test-channel-one']['subscription_count']);
    }

    public function testFailsWhenUsingAnInvalidSignature(): void
    {
        $response = $this->reverbGet('/apps/123456/channels?info=user_count');

        $response->assertStatus(401);
    }

    public function testReturnsEmptyChannelsWhenNoneOccupied(): void
    {
        $response = $this->signedRequest('channels');

        $response->assertStatus(200);
        $this->assertSame('{"channels":[]}', $response->getContent());
    }
}
