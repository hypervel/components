<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Hypervel\Horizon\PhpBinary;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Tests\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class AddSupervisorTest extends IntegrationTestCase
{
    public function testAddSupervisorCommandCreatesNewSupervisorOnMasterProcess()
    {
        $master = new MasterSupervisor();
        $phpBinary = PhpBinary::path();

        $master->loop();

        resolve(HorizonCommandQueue::class)->push($master->commandQueue(), AddSupervisor::class, (new SupervisorOptions('my-supervisor', 'redis'))->toArray());

        $this->assertCount(0, $master->supervisors);

        $master->loop();

        $this->assertCount(1, $master->supervisors);

        $this->assertSame(
            'exec ' . $phpBinary . ' artisan horizon:supervisor my-supervisor redis --workers-name=default --balance=off --max-processes=1 --min-processes=1 --nice=0 --balance-cooldown=3 --balance-max-shift=1 --parent-id=0 --auto-scaling-strategy=time --backoff=0 --max-time=0 --max-jobs=0 --memory=128 --queue="default" --sleep=3 --timeout=60 --tries=0 --rest=0 --concurrency=1',
            $master->supervisors->first()->process->getCommandLine()
        );

        $master->supervisors->each->stop();
    }
}
