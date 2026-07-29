<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Horizon\Console\SupervisorCommand;
use Hypervel\Horizon\SupervisorFactory;
use Hypervel\Tests\Integration\Horizon\Feature\Fixtures\FakeSupervisorFactory;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

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
            ->registerCommand($this->app->make(SupervisorCommand::class));
    }

    public function testSupervisorCommandCanStartSupervisorMonitoring(): void
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory);
        $this->artisan('horizon:supervisor', static::OPTIONS)->assertExitCode(0);

        $this->assertTrue($factory->supervisor->monitoring);
        $this->assertTrue($factory->supervisor->working);
    }

    public function testSupervisorCommandPropagatesTheMonitorStatus(): void
    {
        $factory = new FakeSupervisorFactory;
        $factory->monitorStatus = 12;
        $this->app->instance(SupervisorFactory::class, $factory);

        $this->artisan('horizon:supervisor', static::OPTIONS)->assertExitCode(12);
    }

    public function testSupervisorCommandCanStartPausedSupervisors(): void
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory);
        $this->artisan('horizon:supervisor', ['--paused' => true] + static::OPTIONS);

        $this->assertFalse($factory->supervisor->working);
    }

    public function testSupervisorCommandCanSetProcessNiceness(): void
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory);
        $this->artisan('horizon:supervisor', ['--nice' => 10] + static::OPTIONS);

        $this->assertSame(10, pcntl_getpriority());
    }

    public function testSupervisorCommandPreservesZeroQueue(): void
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory);
        $this->artisan('horizon:supervisor', ['--queue' => '0'] + static::OPTIONS);

        $this->assertSame('0', $factory->supervisor->options->queue);
    }

    public function testSupervisorCommandDefaultsEmptyQueue(): void
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory);
        $this->artisan('horizon:supervisor', ['--queue' => ''] + static::OPTIONS);

        $this->assertSame('default', $factory->supervisor->options->queue);
    }

    public function testSupervisorCommandDefaultsMissingBalanceToOff(): void
    {
        $this->app->instance(SupervisorFactory::class, $factory = new FakeSupervisorFactory);
        $options = static::OPTIONS;
        unset($options['--balance']);

        $this->artisan('horizon:supervisor', $options);

        $this->assertSame('off', $factory->supervisor->options->balance);
    }
}
