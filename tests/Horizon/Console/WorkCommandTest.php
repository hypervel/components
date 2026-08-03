<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Console;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class WorkCommandTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            HorizonServiceProvider::class,
        ];
    }

    public function testOncePassesEveryWorkerOptionAndAcceptsTheWorkersNullResult(): void
    {
        $worker = m::mock(Worker::class);
        $worker->shouldReceive('setName')
            ->once()
            ->with('default')
            ->andReturnSelf();
        $worker->shouldReceive('setCache')
            ->once()
            ->andReturnSelf();
        $worker->shouldReceive('runNextJob')
            ->once()
            ->withArgs(function (string $connection, string $queue, WorkerOptions $options): bool {
                $this->assertSame('sync', $connection);
                $this->assertSame('default', $queue);
                $this->assertSame(7, $options->stopWhenEmptyFor);

                return true;
            })
            ->andReturnNull();

        $this->app->instance(Worker::class, $worker);

        $this->artisan('horizon:work', [
            'connection' => 'sync',
            '--once' => true,
            '--stop-when-empty-for' => 7,
        ])->assertSuccessful();
    }

    public function testHorizonWorkKeepsTheQueueWorkerOptionSurface(): void
    {
        $artisan = $this->app->make(Kernel::class)->getArtisan();
        $queueOptions = array_keys($artisan->find('queue:work')->getDefinition()->getOptions());
        $horizonOptions = array_keys($artisan->find('horizon:work')->getDefinition()->getOptions());

        $this->assertSame([], array_values(array_diff($queueOptions, $horizonOptions)));
        $this->assertSame(['supervisor'], array_values(array_diff($horizonOptions, $queueOptions)));
    }
}
