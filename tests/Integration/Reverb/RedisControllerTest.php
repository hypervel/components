<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

/**
 * HTTP API controller tests with Redis scaling enabled.
 *
 * Ports the Laravel Reverb "gather" controller tests that use $this->usingRedis().
 * These verify that the full pipeline (controller → MetricsHandler → Redis pub/sub →
 * PusherPubSubIncomingMessageHandler → merge results → HTTP response) works end-to-end.
 *
 * Requires a running Redis-enabled test server:
 *   REVERB_SERVER_PORT=19511 REVERB_SCALING_ENABLED=true php tests/Integration/Reverb/server.php
 *
 * @internal
 * @coversNothing
 */
class RedisControllerTest extends ReverbRedisIntegrationTestCase
{
    protected int $serverPort = 19511;

    // ── EventsController ───────────────────────────────────────────────

    public function testCanGatherUserCountsWhenRequested()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'presence-gather-user-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        $result = $this->signedServerPostRequest('events', [
            'name' => 'NewEvent',
            'channels' => ['presence-gather-user-channel', 'test-gather-channel-two'],
            'data' => json_encode(['some' => 'data']),
            'info' => 'user_count',
        ]);

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertArrayHasKey('channels', $body);
        $this->assertSame(1, $body['channels']['presence-gather-user-channel']['user_count']);

        $this->disconnect($client);
    }

    public function testCanGatherSubscriptionCountsWhenRequested()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-gather-sub-channel');

        $result = $this->signedServerPostRequest('events', [
            'name' => 'NewEvent',
            'channels' => ['presence-gather-channel-one', 'test-gather-sub-channel'],
            'data' => json_encode(['some' => 'data']),
            'info' => 'subscription_count',
        ]);

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertSame(1, $body['channels']['test-gather-sub-channel']['subscription_count']);

        $this->disconnect($client);
    }

    public function testCanIgnoreASubscriberWhenPublishingViaRedis()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'redis-ignore-channel');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'redis-ignore-channel');

        $this->signedServerPostRequest('events', [
            'name' => 'TestEvent',
            'channel' => 'redis-ignore-channel',
            'data' => json_encode(['some' => 'data']),
            'socket_id' => $socketIdOne,
        ]);

        $msgTwo = $this->recv($clientTwo);
        $this->assertNotNull($msgTwo);

        $msgOne = $this->recv($clientOne, 0.5);
        $this->assertNull($msgOne);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    // ── EventsBatchController ──────────────────────────────────────────

    public function testCanGatherBatchInfoForEachEvent()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-gather-batch-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'test-gather-batch-two');

        ['client' => $clientThree, 'socketId' => $socketIdThree] = $this->connect();
        $this->subscribe($clientThree, $socketIdThree, 'test-gather-batch-three');

        $result = $this->signedServerPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'presence-gather-batch-channel',
                'data' => json_encode(['some' => 'data']),
                'info' => 'user_count',
            ],
            [
                'name' => 'AnotherEvent',
                'channel' => 'test-gather-batch-two',
                'data' => json_encode(['some' => 'data']),
                'info' => 'subscription_count',
            ],
            [
                'name' => 'YetAnother',
                'channel' => 'test-gather-batch-three',
                'data' => json_encode(['some' => 'data']),
                'info' => 'subscription_count,user_count',
            ],
        ]]);

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertArrayHasKey('batch', $body);
        $this->assertSame(1, $body['batch'][0]['user_count']);
        $this->assertSame(1, $body['batch'][1]['subscription_count']);
        $this->assertSame(1, $body['batch'][2]['subscription_count']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
        $this->disconnect($clientThree);
    }

    public function testCanGatherBatchInfoForSomeEvents()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'presence-gather-batch-some', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        $result = $this->signedServerPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'presence-gather-batch-some',
                'data' => json_encode(['some' => 'data']),
                'info' => 'user_count',
            ],
            [
                'name' => 'AnotherEvent',
                'channel' => 'test-gather-batch-no-info',
                'data' => json_encode(['some' => 'data']),
            ],
        ]]);

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertSame(1, $body['batch'][0]['user_count']);
        $this->assertSame([], (array) $body['batch'][1]);

        $this->disconnect($client);
    }

    // ── ChannelsController ─────────────────────────────────────────────

    public function testCanGatherAllChannelInformation()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'test-gather-all-one');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-gather-all-two', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        $result = $this->signedServerRequest('channels?info=user_count');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertArrayHasKey('test-gather-all-one', $body['channels']);
        $this->assertSame(1, $body['channels']['presence-gather-all-two']['user_count']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testGathersEmptyResultsIfNoMetricsRequested()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'test-gather-empty-one');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'test-gather-empty-two');

        $result = $this->signedServerRequest('channels');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertArrayHasKey('test-gather-empty-one', $body['channels']);
        $this->assertArrayHasKey('test-gather-empty-two', $body['channels']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testOnlyGathersOccupiedChannels()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-gather-occupied');

        $result = $this->signedServerRequest('channels');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertArrayHasKey('test-gather-occupied', $body['channels']);

        $this->disconnect($client);
    }

    public function testCanGatherFilteredChannels()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'test-gather-filter-one');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-gather-filter-two', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        $result = $this->signedServerRequest('channels?filter_by_prefix=presence-&info=user_count');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertArrayNotHasKey('test-gather-filter-one', $body['channels']);
        $this->assertSame(1, $body['channels']['presence-gather-filter-two']['user_count']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    // ── ChannelController ──────────────────────────────────────────────

    public function testCanGatherDataForASingleChannel()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'test-gather-single');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'test-gather-single');

        $result = $this->signedServerRequest('channels/test-gather-single?info=user_count,subscription_count,cache');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertTrue($body['occupied']);
        $this->assertSame(2, $body['subscription_count']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testGathersUnoccupiedWhenNoConnections()
    {
        $result = $this->signedServerRequest('channels/test-gather-unoccupied?info=user_count,subscription_count,cache');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertFalse($body['occupied']);
    }

    public function testCanGatherPresenceChannelAttributes()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-gather-attrs', [
            'user_id' => 123,
            'user_info' => ['name' => 'Taylor'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-gather-attrs', [
            'user_id' => 123,
            'user_info' => ['name' => 'Taylor'],
        ]);

        // Drain member_added on client one
        $this->recv($clientOne, 0.1);

        $result = $this->signedServerRequest('channels/presence-gather-attrs?info=user_count,subscription_count,cache');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['user_count']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testCanGatherCacheChannelAttributes()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'cache-gather-attrs');

        // Drain cache_miss
        $this->recv($client, 0.1);

        // Trigger event to create cached payload
        $this->triggerEvent('cache-gather-attrs', 'CachedEvent', ['some' => 'data']);
        $this->recv($client, 0.1);

        $result = $this->signedServerRequest('channels/cache-gather-attrs?info=subscription_count,cache');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['subscription_count']);
        $this->assertSame('CachedEvent', $body['cache']['event']);
        $this->assertSame('cache-gather-attrs', $body['cache']['channel']);

        $this->disconnect($client);
    }

    public function testCanGatherOnlyTheRequestedAttributes()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-gather-selective');

        // Request all info
        $result = $this->signedServerRequest('channels/test-gather-selective?info=user_count,subscription_count,cache');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], associative: true);
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['subscription_count']);

        // Request only cache (non-cache channel has no cache)
        $result = $this->signedServerRequest('channels/test-gather-selective?info=cache');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], associative: true);
        $this->assertTrue($body['occupied']);
        $this->assertArrayNotHasKey('subscription_count', $body);

        $this->disconnect($client);
    }

    public function testChannelContentLengthWhenGathering()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-gather-cl-single');

        $result = $this->signedServerRequest('channels/test-gather-cl-single?info=subscription_count');

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('content-length', $result['headers']);
        $this->assertSame((string) strlen($result['body']), $result['headers']['content-length']);

        $this->disconnect($client);
    }

    // ── ChannelUsersController ─────────────────────────────────────────

    public function testGathersErrorWhenNonPresenceChannel()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-gather-users-nonpresence');

        $result = $this->signedServerRequest('channels/test-gather-users-nonpresence/users');

        $this->assertSame(400, $result['status']);

        $this->disconnect($client);
    }

    public function testGathersErrorWhenUnoccupiedChannel()
    {
        $result = $this->signedServerRequest('channels/presence-gather-users-empty/users');

        $this->assertSame(404, $result['status']);
    }

    public function testGathersTheUserData()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-gather-users-data', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-gather-users-data', [
            'user_id' => 2,
            'user_info' => ['name' => 'Joe'],
        ]);

        // Drain member_added
        $this->recv($clientOne, 0.1);

        $result = $this->signedServerRequest('channels/presence-gather-users-data/users');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertCount(2, $body['users']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testGathersUniqueUserData()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-gather-users-unique', [
            'user_id' => 2,
            'user_info' => ['name' => 'Taylor'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-gather-users-unique', [
            'user_id' => 2,
            'user_info' => ['name' => 'Joe'],
        ]);

        ['client' => $clientThree, 'socketId' => $socketIdThree] = $this->connect();
        $this->subscribe($clientThree, $socketIdThree, 'presence-gather-users-unique', [
            'user_id' => 3,
            'user_info' => ['name' => 'Jess'],
        ]);

        // Drain member_added notifications
        $this->recv($clientOne, 0.1);
        $this->recv($clientTwo, 0.1);

        $result = $this->signedServerRequest('channels/presence-gather-users-unique/users');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertCount(2, $body['users']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
        $this->disconnect($clientThree);
    }

    // ── ConnectionsController ──────────────────────────────────────────

    public function testCanGatherAConnectionCount()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'test-gather-conn-one');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-gather-conn-two', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        $result = $this->signedServerRequest('connections');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertSame(2, $body['connections']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testCanGatherCorrectCountWhenSubscribedToMultipleChannels()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-gather-multi-one');
        $this->subscribe($client, $socketId, 'test-gather-multi-two');

        $result = $this->signedServerRequest('connections');

        $this->assertSame(200, $result['status']);

        $body = json_decode($result['body'], associative: true);
        $this->assertSame(1, $body['connections']);

        $this->disconnect($client);
    }

    // ── UsersTerminateController ───────────────────────────────────────

    public function testTerminatesUserAcrossServersViaRedis()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-gather-term-channel', [
            'user_id' => '789',
            'user_info' => ['name' => 'User 789'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-gather-term-channel', [
            'user_id' => '987',
            'user_info' => ['name' => 'User 987'],
        ]);

        // Drain member_added
        $this->recv($clientOne, 0.1);

        $result = $this->signedServerPostRequest('users/987/terminate_connections');

        $this->assertSame(200, $result['status']);
        $this->assertSame('{}', $result['body']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    // ── Content-Length headers (scaling path) ───────────────────────────

    public function testEventsContentLengthWhenGathering()
    {
        $result = $this->signedServerPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('2', $result['headers']['content-length']);
    }

    public function testBatchEventsContentLengthWhenGathering()
    {
        $result = $this->signedServerPostRequest('batch_events', ['batch' => [
            ['name' => 'NewEvent', 'channel' => 'test-channel', 'data' => json_encode(['some' => 'data'])],
        ]]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('12', $result['headers']['content-length']);
    }

    public function testChannelsContentLengthWhenGathering()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-gather-cl');

        $result = $this->signedServerRequest('channels?info=user_count');

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('content-length', $result['headers']);
        $this->assertSame((string) strlen($result['body']), $result['headers']['content-length']);

        $this->disconnect($client);
    }

    public function testChannelUsersContentLengthWhenGathering()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'presence-gather-cl-users', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        $result = $this->signedServerRequest('channels/presence-gather-cl-users/users');

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('content-length', $result['headers']);
        $this->assertSame((string) strlen($result['body']), $result['headers']['content-length']);

        $this->disconnect($client);
    }

    public function testConnectionsContentLengthWhenGathering()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-gather-cl-conn');

        $result = $this->signedServerRequest('connections');

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('content-length', $result['headers']);
        $this->assertSame((string) strlen($result['body']), $result['headers']['content-length']);

        $this->disconnect($client);
    }
}
