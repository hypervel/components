<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\HypervelServerProvider;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Tests\Reverb\ReverbTestCase;

/**
 * @internal
 * @coversNothing
 */
class HypervelServerProviderTest extends ReverbTestCase
{
    public function testBindsSwooleTableSharedStateByDefault()
    {
        // Default config: scaling.enabled = false
        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(SwooleTableSharedState::class, $sharedState);
    }

    public function testBindsRedisSharedStateWhenScalingEnabled()
    {
        $this->app['config']->set('reverb.servers.reverb.scaling.enabled', true);

        // Re-register the provider with new config
        $provider = new HypervelServerProvider(
            $this->app,
            $this->app['config']->get('reverb.servers.reverb', [])
        );
        $provider->register();

        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(RedisSharedState::class, $sharedState);
    }

    public function testCreatesSwooleTableWithConfiguredRows()
    {
        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(SwooleTableSharedState::class, $sharedState);
        $this->assertGreaterThan(0, $sharedState->table()->getSize());
    }

    public function testSharedStateIsEagerlyCreated()
    {
        // SharedState should already exist as an instance binding (not lazy)
        // because it must be created before fork for shared memory.
        $this->assertTrue($this->app->bound(SharedState::class));

        $first = $this->app->make(SharedState::class);
        $second = $this->app->make(SharedState::class);

        $this->assertSame($first, $second);
    }
}
