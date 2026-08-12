<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Bus\BatchRepository;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Database\Factories\EntryModelFactory;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Http\Controllers\QueueBatchesController;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;

class QueueBatchesControllerTest extends FeatureTestCase
{
    public function testShowHandlesADeletedQueueBatch(): void
    {
        $entry = EntryModelFactory::new()->create([
            'uuid' => 'deleted-batch',
            'batch_id' => 'related-batch',
            'type' => EntryType::BATCH,
        ]);

        $batchRepository = m::mock(BatchRepository::class);
        $batchRepository->shouldReceive('find')
            ->once()
            ->with('deleted-batch')
            ->andReturnNull();
        $this->app->instance(BatchRepository::class, $batchRepository);

        $result = $this->app->make(QueueBatchesController::class)->show(
            $this->app->make(EntriesRepository::class),
            'deleted-batch',
        );

        $this->assertSame($entry->uuid, $result['entry']->id);
        $this->assertCount(1, $result['batch']);
    }
}
