<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Bus\Batch;
use Hypervel\Bus\Events\BatchDispatched;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\BatchWatcher;
use Hypervel\Telescope\Watchers\JobWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class BatchWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(Repository::class)
            ->set('telescope.watchers', [
                JobWatcher::class => true,
                BatchWatcher::class => true,
            ]);
    }

    public function testJobDispatchRegistersEntries()
    {
        $this->startTelescope();

        $batch = m::mock(Batch::class);
        $batch->id = 'batch-id';
        $batch->options = [
            'queue' => 'on-demand',
            'connection' => 'database',
        ];
        $batch->shouldReceive('toArray')
            ->once()
            ->andReturn(['foo' => 'bar']);
        $batch->shouldReceive('allowsFailures')
            ->once()
            ->andReturn(true);

        $this->app->get(EventDispatcherInterface::class)
            ->dispatch(new BatchDispatched($batch));

        $entries = $this->loadTelescopeEntries()->all();

        $this->assertSame(1, count($entries));

        $this->assertSame(EntryType::BATCH, $entries[0]->type);
        $this->assertSame($batch->id, $entries[0]->uuid);
        $this->assertSame('on-demand', $entries[0]->content['queue']);
        $this->assertSame('database', $entries[0]->content['connection']);
        $this->assertTrue($entries[0]->content['allowsFailures']);
    }
}
