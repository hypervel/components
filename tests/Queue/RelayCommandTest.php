<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Outbox\OutboxWorker;
use Hypervel\Queue\Outbox\OutboxWorkerOptions;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class RelayCommandTest extends TestCase
{
    public function testCommandPassesThePinnedSourceAndTypedOptionsToTheWorker(): void
    {
        $worker = m::mock(OutboxWorker::class);
        $worker->expects('run')->with('orders', m::on(function (OutboxWorkerOptions $options): bool {
            $this->assertEquals(new OutboxWorkerOptions(
                batch: 50,
                sleep: 0.25,
                concurrency: 4,
                maxJobs: 500,
                maxTime: 60,
                memory: 256,
                stopWhenEmpty: true,
                once: true,
            ), $options);

            return true;
        }))->andReturn(12);
        $this->app->instance(OutboxWorker::class, $worker);

        $this->assertSame(12, Artisan::call('queue:relay', [
            'database' => 'orders',
            '--batch' => '50',
            '--sleep' => '0.25',
            '--concurrency' => '4',
            '--max-jobs' => '500',
            '--max-time' => '60',
            '--memory' => '256',
            '--stop-when-empty' => true,
            '--once' => true,
        ]));
    }

    public function testCommandDefaultsToAllConfiguredSources(): void
    {
        $worker = m::mock(OutboxWorker::class);
        $worker->expects('run')->with(null, m::on(fn (OutboxWorkerOptions $options): bool => $options == new OutboxWorkerOptions))->andReturn(0);
        $this->app->instance(OutboxWorker::class, $worker);

        $this->assertSame(0, Artisan::call('queue:relay'));
    }

    public function testPercentageMemoryLimitIsPassedToTheWorkerUnchanged(): void
    {
        $originalLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) * 4));
        $worker = m::mock(OutboxWorker::class);
        $worker->expects('run')->with(null, m::on(fn (OutboxWorkerOptions $options): bool => $options->memory === '60%'))->andReturn(0);
        $this->app->instance(OutboxWorker::class, $worker);

        try {
            $this->assertSame(0, Artisan::call('queue:relay', ['--memory' => '60%']));
        } finally {
            ini_set('memory_limit', $originalLimit);
        }
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsFailBeforeTheWorkerStarts(array $options): void
    {
        $this->app->instance(OutboxWorker::class, m::mock(OutboxWorker::class));
        $this->expectException(InvalidArgumentException::class);

        Artisan::call('queue:relay', $options);
    }

    /**
     * Provide invalid runtime limits at the command boundary.
     */
    public static function invalidOptions(): array
    {
        return [
            'empty batch' => [['--batch' => 0]],
            'oversized batch' => [['--batch' => 1001]],
            'hot-loop sleep' => [['--sleep' => 0]],
            'no capacity' => [['--concurrency' => 0]],
            'negative maximum' => [['--max-jobs' => -1]],
            'zero memory' => [['--memory' => 0]],
            'zero percentage' => [['--memory' => '0%']],
        ];
    }
}
