<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Horizon\Console\SupervisorCommand;
use Hypervel\Horizon\SupervisorFactory;
use Hypervel\Tests\Horizon\Feature\Fixtures\FakeSupervisorFactory;
use Hypervel\Tests\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class SupervisorCommandTest extends IntegrationTestCase
{
    public const OPTIONS = [
        'name' => 'foo',
        'connection' => 'redis',
        '--workers-name' => 'default',
        '--balance' => 'auto',
        '--max-processes' => 2,
        '--min-processes' => 1,
        '--nice' => 0,
        '--balance-cooldown' => 3,
        '--balance-max-shift' => 1,
        '--parent-id' => 99753,
        '--auto-scaling-strategy' => 'time',
        '--backoff' => 0,
        '--max-time' => 0,
        '--max-jobs' => 0,
        '--memory' => 128,
        '--queue' => 'default,foo',
        '--sleep' => 3,
        '--timeout' => 60,
        '--tries' => 1,
        '--rest' => 0,
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)
            ->registerCommand(SupervisorCommand::class);
    }

    public function testSupervisorCommandCanStartSupervisorMonitoring()
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory());
        $this->artisan('horizon:supervisor', static::OPTIONS);

        $this->assertTrue($factory->supervisor->monitoring);
        $this->assertTrue($factory->supervisor->working);
    }

    public function testSupervisorCommandCanStartPausedSupervisors()
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory());
        $this->artisan('horizon:supervisor', ['--paused' => true] + static::OPTIONS);

        $this->assertFalse($factory->supervisor->working);
    }

    public function testSupervisorCommandCanSetProcessNiceness()
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory());
        $this->artisan('horizon:supervisor', ['--nice' => 10] + static::OPTIONS);

        $this->assertSame(10, $this->myNiceness());
    }

    private function myNiceness()
    {
        $pid = getmypid();

        return (int) trim(shell_exec("ps -p {$pid} -o nice="));
    }
}
