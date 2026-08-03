<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\MakeRangeSearchable;
use Hypervel\Support\Facades\Bus;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\Models\UuidSearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

class QueueImportCommandTest extends ScoutTestCase
{
    public function testItProcessesModelsWithRecords(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->expectsOutputToContain('models up to ID: 5')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 1);
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 1 && $job->end === 5;
        });
    }

    public function testItHandlesNoRecordsFound(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->expectsOutputToContain('No records found')
            ->assertSuccessful();

        Bus::assertNotDispatched(MakeRangeSearchable::class);
    }

    public function testItUsesCustomChunkSize(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--chunk' => 2,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 5);
    }

    public function testItUsesDefaultChunkSizeFromConfig(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 1);
    }

    public function testItUsesScoutChunkConfigWhenNoOptionProvided(): void
    {
        $this->app->make('config')->set('scout.chunk.searchable', 3);

        for ($i = 1; $i <= 7; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 3);
    }

    public function testItProcessesLargeDatasetWithChunking(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--chunk' => 10,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 3);
    }

    public function testItHandlesSingleRecord(): void
    {
        SearchableModel::create(['title' => 'Title 1', 'body' => 'Body']);

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 1 && $job->end === 1;
        });
    }

    public function testItHandlesNonSequentialIds(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        SearchableModel::whereIn('id', [2, 4])->delete();

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 1 && $job->end === 5;
        });
    }

    public function testItHandlesChunkSizeLargerThanDataset(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--chunk' => 10,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 1);
    }

    public function testItHandlesChunkSizeOfOne(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--chunk' => 1,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 3);
    }

    public function testItDispatchesJobsWithCorrectStartAndEnd(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--chunk' => 3,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 2);
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 1 && $job->end === 3;
        });
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 4 && $job->end === 5;
        });
    }

    public function testItHandlesInvalidModelClass(): void
    {
        $this->expectException(ScoutException::class);

        $this->artisan('scout:queue-import', ['model' => 'NonExistentModel'])->run();
    }

    public function testItHandlesZeroChunkSize(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--chunk' => 0,
        ])->assertSuccessful();

        // chunk=0 clamps to 1, so 3 models = 3 jobs
        Bus::assertDispatched(MakeRangeSearchable::class, 3);
    }

    public function testItRejectsMalformedIntegerBounds(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        foreach (['01', '1.0', '1e3', 'invalid', (string) PHP_INT_MAX . '0'] as $max) {
            $this->artisan('scout:queue-import', [
                'model' => SearchableModel::class,
                '--min' => '0',
                '--max' => $max,
            ])
                ->expectsOutputToContain('must be valid integers')
                ->assertFailed();
        }

        Bus::assertNotDispatched(MakeRangeSearchable::class);
    }

    public function testItAcceptsSignedIntegerBounds(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => '-2',
            '--max' => '+2',
            '--chunk' => 5,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === -2 && $job->end === 2;
        });
    }

    public function testItRejectsAnInvalidOrder(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--order' => 'sideways',
        ])
            ->expectsOutputToContain('must be either "asc" or "desc"')
            ->assertFailed();

        Bus::assertNotDispatched(MakeRangeSearchable::class);
    }

    public function testItAcceptsCustomMinOption(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => 5,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 5 && $job->end === 10;
        });
    }

    public function testItAcceptsCustomMaxOption(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--max' => 5,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 1 && $job->end === 5;
        });
    }

    public function testItAcceptsBothMinAndMaxOptions(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => 3,
            '--max' => 7,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 3 && $job->end === 7;
        });
    }

    public function testItChunksCustomRangeCorrectly(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => 2,
            '--max' => 8,
            '--chunk' => 3,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 3);
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 2 && $job->end === 4;
        });
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 5 && $job->end === 7;
        });
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 8 && $job->end === 8;
        });
    }

    public function testItQueuesIntegerRangesInDescendingOrder(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => 1,
            '--max' => 7,
            '--chunk' => 3,
            '--order' => 'desc',
        ])
            ->expectsOutputToContain('models down to ID: 1')
            ->assertSuccessful();

        $ranges = Bus::dispatched(MakeRangeSearchable::class)
            ->map(fn (MakeRangeSearchable $job) => [$job->start, $job->end])
            ->all();

        $this->assertSame([[5, 7], [2, 4], [1, 1]], $ranges);
    }

    public function testItQueuesPlatformMaximumIntegerRangeWithoutOverflow(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => PHP_INT_MAX - 1,
            '--max' => PHP_INT_MAX,
            '--chunk' => 500,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === PHP_INT_MAX - 1 && $job->end === PHP_INT_MAX;
        });
    }

    public function testItQueuesPlatformMinimumIntegerRangeWithoutUnderflow(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => PHP_INT_MIN,
            '--max' => PHP_INT_MIN + 1,
            '--chunk' => 500,
            '--order' => 'desc',
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === PHP_INT_MIN && $job->end === PHP_INT_MIN + 1;
        });
    }

    public function testIntegerKeyTypeAliasUsesIntegerRanges(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => IntegerAliasSearchableModel::class,
            '--min' => 0,
            '--max' => 2,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 0 && $job->end === 2;
        });
    }

    public function testItErrorsWhenMinGreaterThanMax(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => 5,
            '--max' => 2,
        ])
            ->expectsOutputToContain('Invalid range')
            ->assertFailed();

        Bus::assertNotDispatched(MakeRangeSearchable::class);
    }

    public function testItAcceptsZeroAsValidMinId(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => 0,
            '--max' => 5,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 0 && $job->end === 5;
        });
    }

    public function testItAcceptsCustomQueueOption(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--queue' => 'custom-queue',
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->queue === 'custom-queue';
        });
    }

    public function testItUsesModelDefaultQueueWhenNoQueueOption(): void
    {
        $this->app->make('config')->set('scout.queue.queue', 'scout-default');

        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->queue === 'scout-default';
        });
    }

    public function testItDispatchesOnModelsConnection(): void
    {
        $this->app->make('config')->set('scout.queue.connection', 'redis');

        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->connection === 'redis';
        });
    }

    public function testItAcceptsAllOptionsTogether(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => SearchableModel::class,
            '--min' => 5,
            '--max' => 15,
            '--chunk' => 4,
            '--queue' => 'custom-queue',
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 3);
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 5 && $job->end === 8 && $job->queue === 'custom-queue';
        });
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 9 && $job->end === 12 && $job->queue === 'custom-queue';
        });
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->start === 13 && $job->end === 15 && $job->queue === 'custom-queue';
        });
    }

    public function testItProcessesUuidKeyedModels(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            UuidSearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        $rows = UuidSearchableModel::orderBy('id')->get();
        $firstUuid = $rows->first()->id;
        $lastUuid = $rows->last()->id;

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => UuidSearchableModel::class])
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 1);
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) use ($firstUuid, $lastUuid) {
            return $job->start === $firstUuid && $job->end === $lastUuid;
        });
    }

    public function testItChunksUuidKeyedModelsCorrectly(): void
    {
        for ($i = 1; $i <= 7; ++$i) {
            UuidSearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => UuidSearchableModel::class,
            '--chunk' => 3,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 3);
    }

    public function testItQueuesMultipleUuidChunksInDescendingOrder(): void
    {
        for ($i = 1; $i <= 7; ++$i) {
            UuidSearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        $expected = UuidSearchableModel::query()->orderByDesc('id')->pluck('id')->chunk(3)->values();

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => UuidSearchableModel::class,
            '--chunk' => 3,
            '--order' => 'desc',
        ])->assertSuccessful();

        $jobs = Bus::dispatched(MakeRangeSearchable::class)->values();

        $this->assertCount(3, $jobs);

        foreach ($expected as $index => $keys) {
            $this->assertSame($keys->last(), $jobs[$index]->start);
            $this->assertSame($keys->first(), $jobs[$index]->end);
        }
    }

    public function testItHandlesNoRecordsForUuidModel(): void
    {
        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => UuidSearchableModel::class])
            ->expectsOutputToContain('No records found')
            ->assertSuccessful();

        Bus::assertNotDispatched(MakeRangeSearchable::class);
    }

    public function testItAcceptsMinMaxFiltersForUuidKeys(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            UuidSearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        $rows = UuidSearchableModel::orderBy('id')->get();
        $minUuid = $rows[1]->id;
        $maxUuid = $rows[3]->id;

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => UuidSearchableModel::class,
            '--min' => $minUuid,
            '--max' => $maxUuid,
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, 1);
        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) use ($minUuid, $maxUuid) {
            return $job->start === $minUuid && $job->end === $maxUuid;
        });
    }

    public function testDatabaseCollationHandlesReversedStringBoundsAsAnEmptyRange(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            UuidSearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        $rows = UuidSearchableModel::orderBy('id')->get();

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => UuidSearchableModel::class,
            '--min' => $rows->last()->id,
            '--max' => $rows->first()->id,
        ])
            ->expectsOutputToContain('No records found')
            ->assertSuccessful();

        Bus::assertNotDispatched(MakeRangeSearchable::class);
    }

    public function testItAcceptsCustomQueueOptionForUuidModels(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            UuidSearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', [
            'model' => UuidSearchableModel::class,
            '--queue' => 'custom-queue',
        ])->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return $job->queue === 'custom-queue';
        });
    }

    public function testItDispatchesJobsWithStringStartAndEnd(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            UuidSearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeRangeSearchable::class]);

        $this->artisan('scout:queue-import', ['model' => UuidSearchableModel::class])
            ->assertSuccessful();

        Bus::assertDispatched(MakeRangeSearchable::class, function (MakeRangeSearchable $job) {
            return is_string($job->start) && is_string($job->end);
        });
    }
}

class IntegerAliasSearchableModel extends SearchableModel
{
    public function getScoutKeyType(): string
    {
        return 'integer';
    }
}
