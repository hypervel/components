<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Carbon\CarbonImmutable;
use Exception;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler;
use Hypervel\Horizon\AutoScaler;
use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Events\WorkerProcessRestarting;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\PhpBinary;
use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Horizon\SystemProcessCounter;
use Hypervel\Horizon\WorkerCommandString;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class SupervisorTest extends IntegrationTestCase
{
    public $phpBinary;

    public $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpBinary = PhpBinary::path();
        $this->supervisor = null;
    }

    protected function tearDownInCoroutine(): void
    {
        $this->terminateProcesses();
    }

    /** @requires extension redis */
    public function testSupervisorCanStartWorkerProcessWithGivenOptions()
    {
        Queue::push(new Jobs\BasicJob());
        $this->assertSame(1, $this->recentJobs());

        $this->supervisor = $supervisor = new Supervisor($this->supervisorOptions());

        $supervisor->scale(1);
        $supervisor->loop();

        $this->wait(function () {
            $this->assertSame('completed', app(JobRepository::class)->getRecent()[0]->status);
        });

        $this->assertCount(1, $supervisor->processes());

        $host = MasterSupervisor::name();
        $this->assertSame(
            'exec ' . $this->phpBinary . ' worker.php redis --name=default --supervisor=' . $host . ':name --backoff=0 --max-time=0 --max-jobs=0 --memory=128 --queue="default" --sleep=3 --timeout=60 --tries=0 --rest=0 --concurrency=1',
            $supervisor->processes()[0]->getCommandLine()
        );
    }

    protected function terminateProcesses()
    {
        if (! $this->supervisor) {
            return;
        }

        $this->supervisor->processes()->each->terminate();

        while (count($this->supervisor->processes()->filter->isRunning()) > 0) {
            usleep(250 * 1000);
        }
    }

    public function testSupervisorStartsMultiplePoolsWhenBalancing()
    {
        $options = $this->supervisorOptions();
        $options->balance = 'simple';
        $options->queue = 'first,second';
        $this->supervisor = $supervisor = new Supervisor($options);

        $supervisor->scale(2);
        $this->assertCount(2, $supervisor->processes());

        $host = MasterSupervisor::name();

        $this->assertSame(
            'exec ' . $this->phpBinary . ' worker.php redis --name=default --supervisor=' . $host . ':name --backoff=0 --max-time=0 --max-jobs=0 --memory=128 --queue="first" --sleep=3 --timeout=60 --tries=0 --rest=0 --concurrency=1',
            $supervisor->processes()[0]->getCommandLine()
        );

        $this->assertSame(
            'exec ' . $this->phpBinary . ' worker.php redis --name=default --supervisor=' . $host . ':name --backoff=0 --max-time=0 --max-jobs=0 --memory=128 --queue="second" --sleep=3 --timeout=60 --tries=0 --rest=0 --concurrency=1',
            $supervisor->processes()[1]->getCommandLine()
        );
    }

    public function testSupervisorStartsPoolsWithQueuesWhenBalancingIsOff()
    {
        $options = $this->supervisorOptions();
        $options->queue = 'first,second';
        $this->supervisor = $supervisor = new Supervisor($options);

        $supervisor->scale(2);
        $this->assertCount(2, $supervisor->processes());

        $host = MasterSupervisor::name();

        $this->assertSame(
            'exec ' . $this->phpBinary . ' worker.php redis --name=default --supervisor=' . $host . ':name --backoff=0 --max-time=0 --max-jobs=0 --memory=128 --queue="first,second" --sleep=3 --timeout=60 --tries=0 --rest=0 --concurrency=1',
            $supervisor->processes()[0]->getCommandLine()
        );

        $this->assertSame(
            'exec ' . $this->phpBinary . ' worker.php redis --name=default --supervisor=' . $host . ':name --backoff=0 --max-time=0 --max-jobs=0 --memory=128 --queue="first,second" --sleep=3 --timeout=60 --tries=0 --rest=0 --concurrency=1',
            $supervisor->processes()[1]->getCommandLine()
        );
    }

    public function testRecentJobsAreCorrectlyMaintained()
    {
        $id = Queue::push(new Jobs\BasicJob());
        $this->assertSame(1, $this->recentJobs());

        $this->supervisor = $supervisor = new Supervisor($this->supervisorOptions());

        $supervisor->scale(1);
        $supervisor->loop();

        $this->wait(function () {
            $this->assertSame(1, $this->recentJobs());
        });

        $this->wait(function () use ($id) {
            $this->assertGreaterThan(0, Redis::connection('horizon')->ttl($id));
        });
    }

    public function testSupervisorMonitorsWorkerProcesses()
    {
        $this->supervisor = $supervisor = new Supervisor($this->supervisorOptions());
        // Force underlying worker to fail...
        WorkerCommandString::$command = 'php wrong.php';

        // Start the supervisor...
        $supervisor->scale(1);
        $supervisor->loop();
        usleep(250 * 1000);

        $supervisor->processes()[0]->restartAgainAt = CarbonImmutable::now()->subMinutes(10);

        // Make sure that the worker attempts restart...
        $restarted = false;
        Event::listen(WorkerProcessRestarting::class, function () use (&$restarted) {
            $restarted = true;
        });

        $supervisor->loop();
        $supervisor->loop();
        $supervisor->loop();

        $this->assertTrue($restarted);
    }

    public function testExceptionsAreCaughtAndHandledDuringLoop()
    {
        $exceptions = Mockery::mock(ExceptionHandler::class);
        $exceptions->shouldReceive('report')->once();
        $this->app->instance(ExceptionHandler::class, $exceptions);

        $this->supervisor = $supervisor = new Fakes\SupervisorThatThrowsException($this->supervisorOptions());

        $supervisor->loop();
    }

    public function testSupervisorInformationIsPersisted()
    {
        $this->supervisor = $supervisor = new Supervisor($options = $this->supervisorOptions());
        $options->balance = 'simple';
        $options->queue = 'default,another';

        $supervisor->scale(2);
        usleep(100 * 1000);

        $supervisor->loop();

        $record = app(SupervisorRepository::class)->find($supervisor->name);
        $this->assertSame('running', $record->status);
        $this->assertSame(2, collect($record->processes)->sum());
        $this->assertSame(2, $record->processes['redis:default,another']);
        $this->assertTrue(isset($record->pid));
        $this->assertSame('redis', $record->options['connection']);

        $supervisor->pause();
        $supervisor->loop();

        $record = app(SupervisorRepository::class)->find($supervisor->name);
        $this->assertSame('paused', $record->status);
    }

    public function testSupervisorRepositoryReturnsNullIfNoSupervisorExistsWithGivenName()
    {
        $repository = app(SupervisorRepository::class);

        $this->assertNull($repository->find('nothing'));
    }

    public function testProcessesCanBeScaledUp()
    {
        $this->supervisor = $supervisor = new Supervisor($options = $this->supervisorOptions());
        $options->balance = 'simple';

        $supervisor->scale(2);
        $supervisor->loop();
        usleep(100 * 1000);

        $this->assertCount(2, $supervisor->processes());
        $this->assertTrue($supervisor->processes()[0]->isRunning());
        $this->assertTrue($supervisor->processes()[1]->isRunning());
    }

    public function testProcessesCanBeScaledDown()
    {
        $this->supervisor = $supervisor = new Supervisor($options = $this->supervisorOptions());
        $options->balance = 'simple';
        $options->sleep = 0;

        $supervisor->scale(3);
        $supervisor->loop();
        usleep(100 * 1000);

        $this->assertCount(3, $supervisor->processes());

        $supervisor->scale(1);
        $supervisor->loop();
        usleep(100 * 1000);

        $this->assertCount(1, $supervisor->processes());
        $this->assertTrue($supervisor->processes()[0]->isRunning());

        // Give processes time to terminate...
        retry(10, function () use ($supervisor) {
            $this->assertCount(0, $supervisor->terminatingProcesses());
        }, 1000);
    }

    // TODO: 討論，執行後會有錯誤訊息
    public function testSupervisorCanRestartProcesses()
    {
        $this->supervisor = $supervisor = new Supervisor($this->supervisorOptions());

        $supervisor->scale(1);
        $supervisor->loop();
        usleep(100 * 1000);

        $pid = $supervisor->processes()[0]->getPid();
        $oldProcess = $supervisor->processes()[0];

        $supervisor->restart();
        usleep(100 * 1000);

        $this->assertNotEquals($pid, $supervisor->processes()[0]->getPid());

        $oldProcess->stop();
        $supervisor->processes()->each->stop();
    }

    /** @requires extension redis */
    public function testProcessesCanBePausedAndContinued()
    {
        $options = $this->supervisorOptions();
        $options->sleep = 0;
        $this->supervisor = $supervisor = new Supervisor($options);

        $supervisor->scale(1);
        $supervisor->loop();
        $this->assertTrue($supervisor->processPools[0]->working);
        usleep(1100 * 1000);

        $supervisor->pause();
        $this->assertFalse($supervisor->processPools[0]->working);
        usleep(1100 * 1000);

        Queue::push(new Jobs\BasicJob());
        usleep(1100 * 1000);

        $this->assertSame(1, $this->recentJobs());

        $supervisor->continue();
        $this->assertTrue($supervisor->processPools[0]->working);

        $this->wait(function () {
            $this->assertSame('completed', app(JobRepository::class)->getRecent()[0]->status);
        });
    }

    public function testDeadProcessesAreNotRestartedWhenPaused()
    {
        $this->supervisor = $supervisor = new Supervisor($this->supervisorOptions());

        $supervisor->scale(1);
        $supervisor->loop();
        usleep(250 * 1000);

        $process = $supervisor->processes()->first();
        $process->stop();
        $supervisor->pause();

        $supervisor->loop();
        usleep(250 * 1000);

        $this->assertFalse($process->isRunning());
    }

    public function testSupervisorProcessesCanBeTerminated()
    {
        $this->supervisor = $supervisor = new Supervisor($options = $this->supervisorOptions());
        $options->sleep = 0;

        $supervisor->scale(1);
        $supervisor->loop();
        usleep(100 * 1000);

        $process = $supervisor->processes()->first();
        $this->assertTrue($process->isRunning());

        $process->terminate();
        usleep(500 * 1000);

        retry(10, function () use ($process) {
            $this->assertFalse($process->isRunning());
        }, 1000);
    }

    public function testSupervisorCanPruneTerminatingProcessesAndReturnTotalProcessCount()
    {
        $this->supervisor = $supervisor = new Supervisor($options = $this->supervisorOptions());
        $options->sleep = 0;

        $supervisor->scale(1);
        usleep(100 * 1000);

        $supervisor->scale(0);
        usleep(500 * 1000);

        $this->assertSame(0, $supervisor->pruneAndGetTotalProcesses());
    }

    public function testTerminatingProcessesThatAreStuckAreHardStopped()
    {
        $this->supervisor = $supervisor = new Supervisor($options = $this->supervisorOptions());
        $options->timeout = 0;
        $options->sleep = 0;

        $supervisor->scale(1);
        $supervisor->loop();
        usleep(100 * 1000);

        $process = $supervisor->processes()->first();
        $supervisor->processPools[0]->markForTermination($process);
        $supervisor->terminatingProcesses();

        $this->assertFalse($process->isRunning());
    }

    public function testSupervisorProcessTerminatesAllWorkersAndExitsOnFullTermination()
    {
        $this->supervisor = $supervisor = new Fakes\SupervisorWithFakeExit($this->supervisorOptions());

        $supervisor->scale(1);
        usleep(100 * 1000);

        $supervisor->persist();
        $supervisor->terminate();

        $this->assertTrue($supervisor->exited);

        // Assert that the supervisor is removed...
        $this->assertNull(app(SupervisorRepository::class)->find($supervisor->name));
    }

    public function testSupervisorLoopProcessesPendingSupervisorCommands()
    {
        $this->supervisor = $supervisor = new Supervisor($this->supervisorOptions());

        $supervisor->scale(1);
        usleep(100 * 1000);

        app(HorizonCommandQueue::class)->push(
            $supervisor->name,
            Commands\FakeCommand::class,
            ['foo' => 'bar']
        );

        // Loop twice to make sure command is only called once...
        $supervisor->loop();
        $supervisor->loop();

        $command = app(Commands\FakeCommand::class);

        $this->assertSame(1, $command->processCount);
        $this->assertEquals($supervisor, $command->supervisor);
        $this->assertEquals(['foo' => 'bar'], $command->options);
    }

    public function testAutoScalerIsCalledOnLoopWhenAutoScaling()
    {
        $options = $this->supervisorOptions();
        $options->autoScale = true;
        $this->supervisor = $supervisor = new Supervisor($options);

        // Mock the scaler...
        $autoScaler = Mockery::mock(AutoScaler::class);
        $autoScaler->shouldReceive('scale')->once()->with($supervisor);
        $this->app->bind(AutoScaler::class, fn () => $autoScaler);

        // Start the supervisor...
        $supervisor->scale(1);
        usleep(100 * 1000);

        $supervisor->loop();

        // Call twice to make sure cool down works...
        $supervisor->loop();
    }

    public function testAutoScalerIsNotCalledOnLoopDuringCooldown()
    {
        $options = $this->supervisorOptions();
        $options->autoScale = true;
        $this->supervisor = $supervisor = new Supervisor($options);

        // Start the supervisor...
        $supervisor->scale(1);

        $time = CarbonImmutable::create();

        $this->assertNull($supervisor->lastAutoScaled);

        $supervisor->lastAutoScaled = null;
        CarbonImmutable::setTestNow($time);
        $supervisor->loop();
        $this->assertTrue($supervisor->lastAutoScaled->eq(CarbonImmutable::now()));

        $supervisor->lastAutoScaled = $time;
        CarbonImmutable::setTestNow($time->addSeconds($supervisor->options->balanceCooldown - 0.01));
        $supervisor->loop();
        $this->assertTrue($supervisor->lastAutoScaled->eq($time));

        $supervisor->lastAutoScaled = $time;
        CarbonImmutable::setTestNow($time->addSeconds($supervisor->options->balanceCooldown));
        $supervisor->loop();
        $this->assertTrue($supervisor->lastAutoScaled->eq(CarbonImmutable::now()));

        $supervisor->lastAutoScaled = $time;
        CarbonImmutable::setTestNow($time->addSeconds($supervisor->options->balanceCooldown + 0.01));
        $supervisor->loop();
        $this->assertTrue($supervisor->lastAutoScaled->eq(CarbonImmutable::now()));
    }

    public function testSupervisorWithDuplicateNameCantBeStarted()
    {
        $this->expectException(Exception::class);

        $options = $this->supervisorOptions();
        $this->supervisor = $supervisor = new Supervisor($options);
        $supervisor->persist();
        $anotherSupervisor = new Supervisor($options);

        $anotherSupervisor->monitor();
    }

    public function testSupervisorProcessesCanBeCountedExternally()
    {
        SystemProcessCounter::$command = 'worker.php';
        $this->supervisor = $supervisor = new Supervisor($options = $this->supervisorOptions());
        $options->balance = 'simple';

        $supervisor->scale(3);
        $supervisor->loop();

        $this->wait(function () use ($supervisor) {
            $this->assertSame(3, $supervisor->totalSystemProcessCount());
        });
    }

    public function testSupervisorDoesNotStartWorkersUntilLoopedAndActive()
    {
        SystemProcessCounter::$command = 'worker.php';
        $this->supervisor = $supervisor = new Supervisor($options = $this->supervisorOptions());
        $options->balance = 'simple';

        $supervisor->scale(3);

        $this->wait(function () use ($supervisor) {
            $this->assertSame(0, $supervisor->totalSystemProcessCount());
        });

        $supervisor->working = false;
        $supervisor->loop();

        $this->wait(function () use ($supervisor) {
            $this->assertSame(0, $supervisor->totalSystemProcessCount());
        });

        $supervisor->working = true;
        $supervisor->loop();

        $this->wait(function () use ($supervisor) {
            $this->assertSame(3, $supervisor->totalSystemProcessCount());
        });
    }

    public function supervisorOptions()
    {
        return tap(new SupervisorOptions(MasterSupervisor::name() . ':name', 'redis'), function ($options) {
            $options->directory = realpath(__DIR__ . '/../');
            WorkerCommandString::$command = 'exec ' . $this->phpBinary . ' worker.php';
        });
    }
}
