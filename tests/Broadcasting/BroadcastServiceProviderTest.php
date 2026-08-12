<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Broadcasting\BroadcastServiceProvider;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;

class BroadcastServiceProviderTest extends TestCase
{
    public function testReloadConfigurationClearsResolvedBroadcastStateWithoutResolvingUnusedManager(): void
    {
        $application = m::mock(Application::class);
        $manager = m::mock(BroadcastManager::class);
        $application->shouldReceive('resolved')->once()->with(BroadcastManager::class)->andReturnTrue();
        $application->shouldReceive('make')->once()->with(BroadcastManager::class)->andReturn($manager);
        $application->shouldReceive('forgetInstance')->once()->with(Broadcaster::class);
        $manager->shouldReceive('forgetDrivers')->once()->andReturnSelf();

        (new BroadcastServiceProvider($application))->reloadConfiguration();

        $unusedApplication = m::mock(Application::class);
        $unusedApplication->shouldReceive('resolved')->once()->with(BroadcastManager::class)->andReturnFalse();
        $unusedApplication->shouldReceive('forgetInstance')->once()->with(Broadcaster::class);
        $unusedApplication->shouldNotReceive('make');

        (new BroadcastServiceProvider($unusedApplication))->reloadConfiguration();
    }
}
