<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Console;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class WorkCommandTest extends TestCase
{
    use VerifiesDoubles;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            HorizonServiceProvider::class,
        ];
    }

    public function testOncePassesEveryWorkerOptionAndAcceptsTheWorkersNullResult(): void
    {
        $worker = Double::for(Worker::class)->strict();
        $worker->expects('setName')
            ->with('default');
        $worker->expects('setCache');
        $worker->expects('runNextJob')
            ->with(Argument::all(function (string $connection, string $queue, WorkerOptions $options): bool {
                $this->assertSame('sync', $connection);
                $this->assertSame('default', $queue);
                $this->assertSame(7, $options->stopWhenEmptyFor);

                return true;
            }))
            ->returns(null);

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
