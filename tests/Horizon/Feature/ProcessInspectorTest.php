<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Exec;
use Hypervel\Horizon\ProcessInspector;
use Hypervel\Tests\Horizon\IntegrationTest;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class ProcessInspectorTest extends IntegrationTest
{
    public function testFindsOrphanedProcessIds()
    {
        $exec = Mockery::mock(Exec::class);
        $exec->shouldReceive('run')->with('pgrep -f [h]orizon')->andReturn([1, 2, 3, 4, 5, 6]);
        $exec->shouldReceive('run')->with('pgrep -f horizon:purge')->andReturn([]);
        $exec->shouldReceive('run')->with('pgrep -P 2')->andReturn([4]);
        $exec->shouldReceive('run')->with('pgrep -P 3')->andReturn([5]);
        $this->app->instance(Exec::class, $exec);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
            [
                'pid' => 2,
            ],
            [
                'pid' => 3,
            ],
        ]);
        $this->app->instance(SupervisorRepository::class, $supervisors);

        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn([
            [
                'pid' => 6,
            ],
        ]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);

        $inspector = resolve(ProcessInspector::class);

        $this->assertEquals([1], $inspector->orphaned());
    }
}
