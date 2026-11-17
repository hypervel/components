<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Exec;
use Hypervel\Horizon\ProcessInspector;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class ProcessInspectorTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testFindsOrphanedProcessIds(): void
    {
        $exec = Double::for(Exec::class)->strict();
        $exec->allows('run')->with('pgrep -f [h]orizon')->returns([1, 2, 3, 4, 5, 6]);
        $exec->allows('run')->with('pgrep -f horizon:purge')->returns([]);
        $exec->allows('run')->with('pgrep -P 2')->returns([4]);
        $exec->allows('run')->with('pgrep -P 3')->returns([5]);
        $this->app->instance(Exec::class, $exec);

        $supervisors = Double::for(SupervisorRepository::class)->strict();
        $supervisors->allows('all')->returns([
            [
                'pid' => 2,
            ],
            [
                'pid' => 3,
            ],
        ]);
        $this->app->instance(SupervisorRepository::class, $supervisors);

        $masters = Double::for(MasterSupervisorRepository::class)->strict();
        $masters->allows('all')->returns([
            [
                'pid' => 6,
            ],
        ]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);

        $inspector = resolve(ProcessInspector::class);

        $this->assertEquals([1], $inspector->orphaned());
    }
}
