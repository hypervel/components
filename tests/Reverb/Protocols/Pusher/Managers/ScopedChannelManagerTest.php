<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Managers;

use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;

/**
 * @internal
 * @coversNothing
 */
class ScopedChannelManagerTest extends ReverbTestCase
{
    public function testForReturnsScopedManagerNotSelf()
    {
        $manager = $this->app->make(ChannelManager::class);
        $connection = new FakeConnection;
        $scoped = $manager->for($connection->app());

        $this->assertInstanceOf(ScopedChannelManager::class, $scoped);
        $this->assertNotInstanceOf(ArrayChannelManager::class, $scoped);
    }

    public function testScopedManagerIsolatesAppState()
    {
        $manager = $this->app->make(ChannelManager::class);

        $appA = new \Hypervel\Reverb\Application('app-1', 'key-1', 'secret-1', 60, 30, ['*'], 10_000);
        $appB = new \Hypervel\Reverb\Application('app-2', 'key-2', 'secret-2', 60, 30, ['*'], 10_000);

        $scopedA = $manager->for($appA);
        $scopedB = $manager->for($appB);

        $scopedA->findOrCreate('channel-a');

        $this->assertCount(1, $scopedA->all());
        $this->assertCount(0, $scopedB->all());
    }

    public function testFindReturnsChannelForCorrectApp()
    {
        $connection = new FakeConnection;
        $scoped = $this->channels();

        $scoped->findOrCreate('test-channel');

        $this->assertNotNull($scoped->find('test-channel'));
        $this->assertNull($scoped->find('nonexistent'));
    }

    public function testConnectionsReturnsOnlyAppConnections()
    {
        $connection = new FakeConnection;
        $scoped = $this->channels();

        $channel = $scoped->findOrCreate('test-channel');
        $channel->subscribe($connection);

        $connections = $scoped->connections();

        $this->assertCount(1, $connections);
        $this->assertArrayHasKey($connection->id(), $connections);
    }
}
