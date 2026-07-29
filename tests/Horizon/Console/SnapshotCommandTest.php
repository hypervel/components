<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Horizon\Console\SnapshotCommand;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Horizon\Lock;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class SnapshotCommandTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            HorizonServiceProvider::class,
        ];
    }

    public function testDefaultSnapshotLockLeavesAThirtySecondSafetyMargin(): void
    {
        $lock = m::mock(Lock::class);
        $lock->shouldReceive('get')->once()->with('metrics:snapshot', 270)->andReturnFalse();

        $this->app->make(SnapshotCommand::class)->handle(
            $lock,
            m::mock(MetricsRepository::class),
        );
    }

    public function testSnapshotLockDefaultSurvivesReplaceWholeMetricsConfiguration(): void
    {
        config(['horizon.metrics' => [
            'trim_snapshots' => ['job' => 12, 'queue' => 12],
        ]]);

        $lock = m::mock(Lock::class);
        $lock->shouldReceive('get')->once()->with('metrics:snapshot', 270)->andReturnFalse();

        $this->app->make(SnapshotCommand::class)->handle(
            $lock,
            m::mock(MetricsRepository::class),
        );
    }
}
