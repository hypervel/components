<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Reverb\Events\ChannelCreated;
use Hypervel\Reverb\Events\ChannelRemoved;
use Hypervel\Reverb\Events\ConnectionClosed;
use Hypervel\Reverb\Events\ConnectionEstablished;
use Hypervel\Reverb\Events\ConnectionPruned;
use Hypervel\Reverb\Events\MessageReceived;
use Hypervel\Reverb\Events\MessageSent;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Reverb\ReverbServiceProvider;
use Hypervel\Support\Facades\DB;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\ReverbWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use Swoole\Server;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    ReverbWatcher::class => [
        'enabled' => true,
        'events' => [
            'connection_established',
            'connection_closed',
            'channel_created',
            'channel_removed',
            'connection_pruned',
        ],
    ],
])]
class ReverbWatcherTest extends FeatureTestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ReverbServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('reverb.apps.apps', [
            [
                'key' => 'reverb-key',
                'secret' => 'reverb-secret',
                'app_id' => '123456',
                'options' => [
                    'host' => 'localhost',
                    'port' => 443,
                    'scheme' => 'https',
                    'useTLS' => true,
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_message_size' => 10_000,
                'accept_client_events_from' => 'members',
            ],
        ]);

        $redisConnection = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 1,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ];

        $app['config']->set('database.redis.options', []);
        $app['config']->set('database.redis.default', $redisConnection);
        $app['config']->set('database.redis.queue', $redisConnection);
        $app['config']->set('database.redis.reverb', $redisConnection);

        $server = m::mock(Server::class);
        $server->shouldReceive('sendMessage')->zeroOrMoreTimes();
        $server->setting = ['worker_num' => 1];
        $server->worker_id = 0;
        $app->instance(Server::class, $server);
    }

    public function testRecordsConnectionEstablished()
    {
        $connection = new FakeConnection;

        event(new ConnectionEstablished($connection));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REVERB, $entry->type);
        $this->assertSame('connection_established', $entry->content['event']);
        $this->assertSame($connection->id(), $entry->content['socket_id']);
        $this->assertSame('123456', $entry->content['app_id']);
        $this->assertSame('http://localhost', $entry->content['origin']);
    }

    public function testRecordsConnectionClosed()
    {
        $connection = new FakeConnection;

        event(new ConnectionClosed($connection));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REVERB, $entry->type);
        $this->assertSame('connection_closed', $entry->content['event']);
        $this->assertSame($connection->id(), $entry->content['socket_id']);
        $this->assertSame('123456', $entry->content['app_id']);
    }

    public function testRecordsChannelCreated()
    {
        $channel = new Channel('test-channel');

        event(new ChannelCreated($channel));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REVERB, $entry->type);
        $this->assertSame('channel_created', $entry->content['event']);
        $this->assertSame('test-channel', $entry->content['channel']);
    }

    public function testRecordsChannelRemoved()
    {
        $channel = new Channel('presence-room');

        event(new ChannelRemoved($channel));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REVERB, $entry->type);
        $this->assertSame('channel_removed', $entry->content['event']);
        $this->assertSame('presence-room', $entry->content['channel']);
    }

    public function testRecordsConnectionPruned()
    {
        $connection = new FakeConnection;
        $channelConnection = new ChannelConnection($connection);

        event(new ConnectionPruned($channelConnection));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::REVERB, $entry->type);
        $this->assertSame('connection_pruned', $entry->content['event']);
        $this->assertSame($connection->id(), $entry->content['socket_id']);
        $this->assertSame('123456', $entry->content['app_id']);
    }

    public function testDoesNotRecordMessageReceivedByDefault()
    {
        $connection = new FakeConnection;

        event(new MessageReceived($connection, '{"event":"pusher:ping"}'));

        $entries = $this->loadTelescopeEntries();

        $this->assertTrue($entries->where('type', EntryType::REVERB)->isEmpty());
    }

    public function testRecordsMessageReceivedWhenOptedIn()
    {
        $this->registerWatcherWithEvents([
            'message_received',
        ]);

        $connection = new FakeConnection;

        event(new MessageReceived($connection, '{"event":"pusher:ping"}'));

        $entry = $this->loadTelescopeEntries()->where('type', EntryType::REVERB)->first();

        $this->assertNotNull($entry);
        $this->assertSame('message_received', $entry->content['event']);
        $this->assertSame($connection->id(), $entry->content['socket_id']);
        $this->assertSame('123456', $entry->content['app_id']);
        $this->assertSame('{"event":"pusher:ping"}', $entry->content['message']);
    }

    public function testDoesNotRecordMessageSentByDefault()
    {
        $connection = new FakeConnection;

        event(new MessageSent($connection, '{"event":"pusher:pong"}'));

        $entries = $this->loadTelescopeEntries();

        $this->assertTrue($entries->where('type', EntryType::REVERB)->isEmpty());
    }

    public function testRecordsMessageSentWhenOptedIn()
    {
        $this->registerWatcherWithEvents([
            'message_sent',
        ]);

        $connection = new FakeConnection;

        event(new MessageSent($connection, '{"event":"pusher:pong"}'));

        $entry = $this->loadTelescopeEntries()->where('type', EntryType::REVERB)->first();

        $this->assertNotNull($entry);
        $this->assertSame('message_sent', $entry->content['event']);
        $this->assertSame($connection->id(), $entry->content['socket_id']);
        $this->assertSame('{"event":"pusher:pong"}', $entry->content['message']);
    }

    public function testDefaultConfigRecordsOnlyLifecycleEvents()
    {
        $connection = new FakeConnection;
        $channel = new Channel('test-channel');

        event(new ConnectionEstablished($connection));
        event(new ConnectionClosed($connection));
        event(new ChannelCreated($channel));
        event(new ChannelRemoved($channel));
        event(new ConnectionPruned(new ChannelConnection($connection)));
        event(new MessageReceived($connection, '{"event":"test"}'));
        event(new MessageSent($connection, '{"event":"test"}'));

        $entries = $this->loadTelescopeEntries()->where('type', EntryType::REVERB);

        $this->assertCount(5, $entries);
    }

    public function testEntryIncludesAppTag()
    {
        $connection = new FakeConnection;

        event(new ConnectionEstablished($connection));

        $entry = $this->loadTelescopeEntries()->first();

        $tags = DB::connection('testing')->table('telescope_entries_tags')
            ->where('entry_uuid', $entry->uuid)
            ->pluck('tag')
            ->all();

        $this->assertContains('App:123456', $tags);
    }

    public function testEntryIncludesChannelTag()
    {
        $channel = new Channel('private-notifications');

        event(new ChannelCreated($channel));

        $entry = $this->loadTelescopeEntries()->first();

        $tags = DB::connection('testing')->table('telescope_entries_tags')
            ->where('entry_uuid', $entry->uuid)
            ->pluck('tag')
            ->all();

        $this->assertContains('Channel:private-notifications', $tags);
    }

    public function testMessageContentTruncatedToSizeLimit()
    {
        $this->registerWatcherWithEvents(
            ['message_received'],
            ['message_size_limit' => 1],
        );

        $connection = new FakeConnection;
        $longMessage = str_repeat('x', 2048);

        event(new MessageReceived($connection, $longMessage));

        $entry = $this->loadTelescopeEntries()->where('type', EntryType::REVERB)->first();

        $this->assertNotNull($entry);
        $this->assertLessThanOrEqual(1024 + 3, strlen($entry->content['message'])); // 1KB + "..."
    }

    public function testDoesNotRecordWhenTelescopePaused()
    {
        // Clear the recording state set by FeatureTestCase::setUp() so the
        // watcher's startRecording() actually checks the pause cache key.
        Telescope::stopRecording();

        $this->app->make(CacheFactory::class)->forever('telescope:pause-recording', true);

        $connection = new FakeConnection;

        event(new ConnectionEstablished($connection));

        $this->app->make(CacheFactory::class)->forget('telescope:pause-recording');
        Telescope::startRecording();

        $entries = $this->loadTelescopeEntries();

        $this->assertTrue($entries->where('type', EntryType::REVERB)->isEmpty());
    }

    public function testDoesNotStopRecordingWhenAlreadyActive()
    {
        // Simulates a MessageSent event firing inside an HTTP request coroutine
        // where Telescope recording was already started by ListensForStorageOpportunities.
        // FeatureTestCase::setUp() calls startRecording(), so recording is active here.
        $this->assertTrue(Telescope::isRecording());

        $this->registerWatcherWithEvents(['message_sent']);

        $connection = new FakeConnection;
        event(new MessageSent($connection, '{"event":"test"}'));

        // Recording must still be active after the watcher handled the event.
        // If the watcher unconditionally calls stopRecording(), this fails and
        // the rest of the coroutine (RequestWatcher, QueryWatcher, etc.) is blind.
        $this->assertTrue(Telescope::isRecording());
    }

    /**
     * Register an additional ReverbWatcher with specific events.
     */
    protected function registerWatcherWithEvents(array $events, array $options = []): void
    {
        $watcher = new ReverbWatcher;
        $watcher->setOptions(array_merge([
            'enabled' => true,
            'events' => $events,
        ], $options));

        $watcher->register($this->app);
    }
}
