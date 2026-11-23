<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Horizon\Console\SnapshotCommand;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Horizon\Lock;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SnapshotCommandTest extends TestCase
{
    use VerifiesDoubles;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            HorizonServiceProvider::class,
        ];
    }

    public function testDefaultSnapshotLockLeavesAThirtySecondSafetyMargin(): void
    {
        $lock = Double::for(Lock::class)->strict();
        $lock->expects('get')->with(
            'metrics:snapshot',
            270,
        )->returns(false);

        $this->app->make(SnapshotCommand::class)->handle(
            $lock,
            Double::for(MetricsRepository::class)->strict(),
        );
    }

    public function testSnapshotLockUsesItsDefaultWhenMetricsConfigurationIsReplaced(): void
    {
        config(['horizon.metrics' => [
            'trim_snapshots' => ['job' => 12, 'queue' => 12],
        ]]);

        $lock = Double::for(Lock::class)->strict();
        $lock->expects('get')->with(
            'metrics:snapshot',
            270,
        )->returns(false);

        $this->app->make(SnapshotCommand::class)->handle(
            $lock,
            Double::for(MetricsRepository::class)->strict(),
        );
    }
}
