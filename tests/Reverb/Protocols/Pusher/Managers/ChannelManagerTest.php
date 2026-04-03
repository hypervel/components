<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Managers;

use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;

/**
 * @internal
 * @coversNothing
 */
class ChannelManagerTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected ScopedChannelManager $channelManager;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection();
        $this->channelManager = $this->app->make(ChannelManager::class)
            ->for($this->connection->app());
        $this->channel = $this->channelManager->findOrCreate('test-channel-0');
    }

    public function testCanSubscribeToAChannel()
    {
        collect(static::factory(5))
            ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

        $this->assertCount(5, $this->channel->connections());
    }

    public function testCanUnsubscribeFromAChannel()
    {
        $connections = collect(static::factory(5))
            ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

        $this->channel->unsubscribe($connections->first()->connection());

        $this->assertCount(4, $this->channel->connections());
    }

    public function testCanGetAllChannels()
    {
        $channels = collect(['test-channel-1', 'test-channel-2', 'test-channel-3']);

        $channels->each(fn ($channel) => $this->channelManager->findOrCreate($channel)->subscribe($this->connection));

        foreach ($this->channelManager->all() as $index => $channel) {
            $this->assertSame($index, $channel->name());
        }

        $this->assertCount(4, $this->channelManager->all());
    }

    public function testCanDetermineWhetherAChannelExists()
    {
        $this->channelManager->findOrCreate('test-channel-1');

        $this->assertTrue($this->channelManager->exists('test-channel-1'));
        $this->assertFalse($this->channelManager->exists('test-channel-2'));
    }

    public function testCanGetAllConnectionsSubscribedToAChannel()
    {
        $connections = collect(static::factory(5))
            ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

        $connectionKeys = array_keys($this->channel->connections());

        $connections->each(fn ($connection) => $this->assertContains($connection->id(), $connectionKeys));
    }

    public function testCanUnsubscribeAConnectionFromAllChannels()
    {
        $channels = collect(['test-channel-0', 'test-channel-1', 'test-channel-2']);

        $channels->each(fn ($channel) => $this->channelManager->findOrCreate($channel)->subscribe($this->connection));

        collect($this->channelManager->all())->each(fn ($channel) => $this->assertCount(1, $channel->connections()));

        $this->channelManager->unsubscribeFromAll($this->connection);

        collect($this->channelManager->all())->each(fn ($channel) => $this->assertCount(0, $channel->connections()));
    }

    public function testCanGetTheDataForAConnectionSubscribedToAChannel()
    {
        collect(static::factory(5))->each(fn ($connection) => $this->channel->subscribe(
            $connection->connection(),
            data: json_encode(['name' => 'Joe'])
        ));

        collect($this->channel->connections())->each(function ($connection) {
            $this->assertSame(['name' => 'Joe'], $connection->data());
        });
    }

    public function testCanGetAllConnectionsForAllChannels()
    {
        $connections = static::factory(12);

        $channelOne = $this->channelManager->findOrCreate('test-channel-0');
        $channelTwo = $this->channelManager->findOrCreate('test-channel-1');
        $channelThree = $this->channelManager->findOrCreate('test-channel-2');

        $connections = collect($connections)->split(3);

        $connections->first()->each(function ($connection) use ($channelOne, $channelTwo, $channelThree) {
            $channelOne->subscribe($connection->connection());
            $channelTwo->subscribe($connection->connection());
            $channelThree->subscribe($connection->connection());
        });

        $connections->get(1)->each(function ($connection) use ($channelTwo, $channelThree) {
            $channelTwo->subscribe($connection->connection());
            $channelThree->subscribe($connection->connection());
        });

        $connections->last()->each(function ($connection) use ($channelThree) {
            $channelThree->subscribe($connection->connection());
        });

        $this->assertCount(4, $channelOne->connections());
        $this->assertCount(8, $channelTwo->connections());
        $this->assertCount(12, $channelThree->connections());
    }
}
