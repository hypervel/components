<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Exec;
use Hypervel\Horizon\ProcessInspector;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ProcessInspectorTest extends IntegrationTestCase
{
    public function testFindsOrphanedProcessIds()
    {
        $exec = m::mock(Exec::class);
        $exec->shouldReceive('run')->with('pgrep -f [h]orizon')->andReturn([1, 2, 3, 4, 5, 6]);
        $exec->shouldReceive('run')->with('pgrep -f horizon:purge')->andReturn([]);
        $exec->shouldReceive('run')->with('pgrep -P 2')->andReturn([4]);
        $exec->shouldReceive('run')->with('pgrep -P 3')->andReturn([5]);
        $this->app->instance(Exec::class, $exec);

        $supervisors = m::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
            [
                'pid' => 2,
            ],
            [
                'pid' => 3,
            ],
        ]);
        $this->app->instance(SupervisorRepository::class, $supervisors);

        $masters = m::mock(MasterSupervisorRepository::class);
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
