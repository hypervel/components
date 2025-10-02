<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Exception;
use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Hypervel\Horizon\PhpBinary;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Horizon\SupervisorProcess;
use Hypervel\Tests\Horizon\Feature\Fixtures\EternalSupervisor;
use Hypervel\Tests\Horizon\Feature\Fixtures\SupervisorProcessWithFakeRestart;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Hypervel\Horizon\WorkerCommandString;
use Hypervel\Support\Facades\Redis;
use Mockery;
use Symfony\Component\Process\Process;

/**
 * @internal
 * @coversNothing
 */
class MasterSupervisorTest extends IntegrationTestCase
{
    public function testNamesCanBeCustomized()
    {
        MasterSupervisor::determineNameUsing(function () {
            return 'test-name';
        });

        $master = new MasterSupervisor();

        $this->assertStringStartsWith('test-name', $master->name);
        $this->assertStringStartsWith('test-name', $master->name());
        $this->assertStringStartsWith('test-name', $master->name());

        MasterSupervisor::$nameResolver = null;
    }

    public function testMasterProcessMarksCleanExitsAsDeadAndRemovesThem()
    {
        $process = Mockery::mock(Process::class);
        $master = new MasterSupervisor();
        $master->working = true;
        $master->supervisors[] = $supervisorProcess = new SupervisorProcess(
            $this->supervisorOptions(),
            $process
        );

        $process->shouldReceive('isStarted')->andReturn(true);
        $process->shouldReceive('isRunning')->andReturn(false);
        $process->shouldReceive('getExitCode')->andReturn(0);

        $master->loop();

        $this->assertTrue($supervisorProcess->dead);
        $this->assertCount(0, $master->supervisors);
    }

    public function testMasterProcessMarksDuplicatesAsDeadAndRemovesThem()
    {
        $process = Mockery::mock(Process::class);
        $master = new MasterSupervisor();
        $master->working = true;
        $master->supervisors[] = $supervisorProcess = new SupervisorProcess(
            $this->supervisorOptions(),
            $process
        );

        $process->shouldReceive('isStarted')->andReturn(true);
        $process->shouldReceive('isRunning')->andReturn(false);
        $process->shouldReceive('getExitCode')->andReturn(13);

        $master->loop();

        $this->assertTrue($supervisorProcess->dead);
        $this->assertCount(0, $master->supervisors);
    }

    public function testMasterProcessRestartsUnexpectedExits()
    {
        $process = Mockery::mock(Process::class);
        $master = new MasterSupervisor();
        $master->working = true;
        $master->supervisors[] = $supervisorProcess = new SupervisorProcessWithFakeRestart(
            $this->supervisorOptions(),
            $process
        );

        $process->shouldReceive('isStarted')->andReturn(true);
        $process->shouldReceive('isRunning')->andReturn(false);
        $process->shouldReceive('getExitCode')->andReturn(50);

        $master->loop();

        $this->assertTrue($supervisorProcess->dead);
        $commands = Redis::connection('horizon')->lrange(
            'commands:' . MasterSupervisor::commandQueueFor(MasterSupervisor::name()),
            0,
            -1
        );

        $this->assertCount(1, $commands);
        $command = (object) json_decode($commands[0], true);

        $this->assertCount(0, $master->supervisors);
        $this->assertSame(AddSupervisor::class, $command->command);
        $this->assertSame('default', $command->options['queue']);
    }

    public function testMasterProcessRestartsProcessesThatNeverStarted()
    {
        $process = Mockery::mock(Process::class);
        $master = new MasterSupervisor();
        $master->working = true;
        $master->supervisors[] = $supervisorProcess = new SupervisorProcessWithFakeRestart(
            $this->supervisorOptions(),
            $process
        );

        $process->shouldReceive('isStarted')->andReturn(false);

        $master->loop();

        $this->assertFalse($supervisorProcess->dead);
        $this->assertCount(1, $master->supervisors);
        $this->assertTrue($supervisorProcess->wasRestarted);
    }

    public function testMasterProcessStartsUnstartedProcessesWhenUnpaused()
    {
        $process = Mockery::mock(Process::class);
        $master = new MasterSupervisor();
        $master->supervisors[] = $supervisorProcess = new SupervisorProcessWithFakeRestart(
            $this->supervisorOptions(),
            $process
        );

        $process->shouldReceive('isStarted')->andReturn(false);
        $process->shouldReceive('isRunning')->andReturn(false);

        $master->loop();

        $this->assertFalse($supervisorProcess->dead);
        $this->assertCount(1, $master->supervisors);
        $this->assertTrue($supervisorProcess->wasRestarted);
    }

    public function testMasterProcessLoopProcessesPendingCommands()
    {
        $master = new MasterSupervisor();
        $master->working = true;

        resolve(HorizonCommandQueue::class)->push(
            $master->commandQueue(),
            Commands\FakeMasterCommand::class,
            ['foo' => 'bar']
        );

        // Loop twice to make sure command is only called once...
        $master->loop();
        $master->loop();

        // In Hypervel, we use the singleton pattern by default.
        $command = resolve(Commands\FakeMasterCommand::class);

        $this->assertSame(1, $command->processCount);
        $this->assertEquals($master, $command->master);
        $this->assertEquals(['foo' => 'bar'], $command->options);
    }

    public function testMasterProcessInformationIsPersisted()
    {
        $process = Mockery::mock(Process::class);
        $master = new MasterSupervisor();
        $master->working = true;
        $master->supervisors[] = new SupervisorProcess($this->supervisorOptions(), $process);
        $process->shouldReceive('isStarted')->andReturn(true);
        $process->shouldReceive('isRunning')->andReturn(true);
        $process->shouldReceive('signal');

        $master->loop();

        $masterRecord = resolve(MasterSupervisorRepository::class)->find($master->name);

        $this->assertNotNull($masterRecord->pid);
        $this->assertEquals([MasterSupervisor::name() . ':name'], $masterRecord->supervisors);
        $this->assertSame('running', $masterRecord->status);

        $master->pause();
        $master->loop();

        $masterRecord = resolve(MasterSupervisorRepository::class)->find($master->name);
        $this->assertSame('paused', $masterRecord->status);
    }

    public function testMasterProcessShouldNotAllowDuplicateMasterProcessOnSameMachine()
    {
        $this->expectException(Exception::class);

        $master = new MasterSupervisor();
        $master->working = true;
        $master2 = new MasterSupervisor();
        $master2->working = true;

        $master->persist();
        $master->monitor();
    }

    public function testSupervisorRepositoryReturnsNullIfNoSupervisorExistsWithGivenName()
    {
        $repository = resolve(MasterSupervisorRepository::class);

        $this->assertNull($repository->find('nothing'));
    }

    public function testSupervisorProcessTerminatesAllWorkersAndExitsOnFullTermination()
    {
        $master = new Fakes\MasterSupervisorWithFakeExit();
        $master->working = true;

        $master->persist();
        $master->terminate();

        $this->assertTrue($master->exited);

        // Assert that the supervisor is removed...
        $this->assertNull(resolve(MasterSupervisorRepository::class)->find($master->name));
    }

    public function testSupervisorContinuesTerminationIfSupervisorsTakeTooLong()
    {
        $master = new Fakes\MasterSupervisorWithFakeExit();
        $master->working = true;

        $master->supervisors = collect([new EternalSupervisor()]);

        $master->persist();
        $master->terminate();

        $this->assertTrue($master->exited);
    }

    protected function supervisorOptions()
    {
        return tap(new SupervisorOptions(MasterSupervisor::name() . ':name', 'redis'), function ($options) {
            $phpBinary = PhpBinary::path();
            $options->directory = realpath(__DIR__ . '/../');

            WorkerCommandString::$command = 'exec ' . $phpBinary . ' worker.php';
        });
    }
}
