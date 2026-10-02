<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Managers;

use Hypervel\Reverb\Events\ChannelRemoved;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ScopedChannelManager;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ChannelManagerTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected ScopedChannelManager $channelManager;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection;
        $this->channelManager = $this->app->make(ChannelManager::class)
            ->for($this->connection->app());
        $this->channel = $this->channelManager->findOrCreate('test-channel-0');
    }

    public function testCanSubscribeToAChannel(): void
    {
        collect(static::factory(5))
            ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

        $this->assertCount(5, $this->channel->connections());
    }

    public function testCanUnsubscribeFromAChannel(): void
    {
        $connections = collect(static::factory(5))
            ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

        $this->channel->unsubscribe($connections->first()->connection());

        $this->assertCount(4, $this->channel->connections());
    }

    public function testCanGetAllChannels(): void
    {
        $channels = collect(['test-channel-1', 'test-channel-2', 'test-channel-3']);

        $channels->each(fn ($channel) => $this->channelManager->findOrCreate($channel)->subscribe($this->connection));

        foreach ($this->channelManager->all() as $index => $channel) {
            $this->assertSame($index, $channel->name());
        }

        $this->assertCount(4, $this->channelManager->all());
    }

    public function testCanDetermineWhetherAChannelExists(): void
    {
        $this->channelManager->findOrCreate('test-channel-1');

        $this->assertTrue($this->channelManager->exists('test-channel-1'));
        $this->assertFalse($this->channelManager->exists('test-channel-2'));
    }

    public function testCanGetAllConnectionsSubscribedToAChannel(): void
    {
        $connections = collect(static::factory(5))
            ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

        $connectionKeys = array_keys($this->channel->connections());

        $connections->each(fn ($connection) => $this->assertContains($connection->id(), $connectionKeys));
    }

    public function testCanUnsubscribeAConnectionFromAllChannels(): void
    {
        $channels = collect(['test-channel-0', 'test-channel-1', 'test-channel-2']);

        $channels->each(fn ($channel) => $this->channelManager->findOrCreate($channel)->subscribe($this->connection));

        collect($this->channelManager->all())->each(fn ($channel) => $this->assertCount(1, $channel->connections()));

        $this->channelManager->unsubscribeFromAll($this->connection);

        collect($this->channelManager->all())->each(fn ($channel) => $this->assertCount(0, $channel->connections()));
    }

    #[DataProvider('unsubscribeFailures')]
    public function testUnsubscribeFailureDoesNotLeaveMembershipInLaterChannels(bool $cancel): void
    {
        $first = $this->channel;
        $second = $this->channelManager->findOrCreate('second');
        $first->subscribe($this->connection);
        $second->subscribe($this->connection);
        $failure = $cancel ? new CanceledException : new RuntimeException('listener failed');
        $laterFailure = new RuntimeException('later listener failed');
        $this->app->make('events')->listen(ChannelRemoved::class, static function (ChannelRemoved $event) use ($first, $failure, $laterFailure): never {
            throw $event->channel === $first ? $failure : $laterFailure;
        });
        $caught = null;

        try {
            $this->channelManager->unsubscribeFromAll($this->connection);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertFalse($first->subscribed($this->connection));
        $this->assertFalse($second->subscribed($this->connection));
    }

    /**
     * Supply ordinary failure and cancellation during channel cleanup.
     */
    public static function unsubscribeFailures(): array
    {
        return [[false], [true]];
    }

    public function testCanGetTheDataForAConnectionSubscribedToAChannel(): void
    {
        collect(static::factory(5))->each(fn ($connection) => $this->channel->subscribe(
            $connection->connection(),
            data: json_encode(['name' => 'Joe'])
        ));

        collect($this->channel->connections())->each(function ($connection) {
            $this->assertSame(['name' => 'Joe'], $connection->data());
        });
    }

    public function testCanFindAConnectionBySocketIdWithoutFlatteningEveryChannel(): void
    {
        $connections = collect(static::factory(3));

        $channelOne = $this->channelManager->findOrCreate('test-channel-0');
        $channelTwo = $this->channelManager->findOrCreate('test-channel-1');

        $connections->each(function (ChannelConnection $connection) use ($channelOne, $channelTwo): void {
            $channelOne->subscribe($connection->connection());
            $channelTwo->subscribe($connection->connection());
        });

        $target = $connections->first()->connection();

        $this->assertSame($target, $this->channelManager->findConnection($target->id())?->connection());
    }

    public function testReturnsNullFromFindConnectionWhenTheSocketIdIsUnknown(): void
    {
        $this->assertNull($this->channelManager->findConnection('does-not-exist'));
    }

    public function testCanGetAllConnectionsForAllChannels(): void
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

    public function testPrefersAConnectionWhichKnowsItsSubscriberOverAnAnonymousOne(): void
    {
        $anonymous = $this->channelManager->findOrCreate('anonymous-channel');
        $identified = $this->channelManager->findOrCreate('identified-channel');

        // The anonymous channel is created and subscribed to first, so it is the
        // one a merge keyed on the connection identifier would otherwise keep.
        $anonymous->subscribe($this->connection);
        $identified->subscribe($this->connection, data: json_encode(['user_id' => '1']));

        $connections = $this->channelManager->connections();

        $this->assertCount(1, $connections);
        $this->assertSame('1', $connections[$this->connection->id()]->data('user_id'));
    }

    public function testKeepsTheSubscriberWhenTheIdentifiedChannelIsSubscribedToFirst(): void
    {
        $identified = $this->channelManager->findOrCreate('identified-channel');
        $anonymous = $this->channelManager->findOrCreate('anonymous-channel');

        $identified->subscribe($this->connection, data: json_encode(['user_id' => '1']));
        $anonymous->subscribe($this->connection);

        $connections = $this->channelManager->connections();

        $this->assertCount(1, $connections);
        $this->assertSame('1', $connections[$this->connection->id()]->data('user_id'));
    }
}
