<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Storage;

use Exception;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Telescope\Database\Factories\EntryModelFactory;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\EntryUpdate;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\IncomingExceptionEntry;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEntriesRepositoryTest extends FeatureTestCase
{
    public function testFindEntryByUuid()
    {
        $entry = EntryModelFactory::new()->create();

        $result = $this->app
            ->get(DatabaseEntriesRepository::class)
            ->find($entry->uuid)
            ->jsonSerialize();

        $this->assertSame($entry->uuid, $result['id']);
        $this->assertSame($entry->batch_id, $result['batch_id']);
        $this->assertSame($entry->type, $result['type']);
        $this->assertSame($entry->content, $result['content']);

        $this->assertNull($result['sequence']);
    }

    public function testUpdate()
    {
        $entry = EntryModelFactory::new()->create();

        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $result = $repository
            ->find($entry->uuid)
            ->jsonSerialize();

        $failedUpdates = $repository->update(collect([
            new EntryUpdate($result['id'], $result['type'], ['content' => ['foo' => 'bar']]),
            new EntryUpdate('missing-id', $result['type'], ['content' => ['foo' => 'bar']]),
        ]));

        $this->assertCount(1, $failedUpdates);
        $this->assertSame('missing-id', $failedUpdates->first()->uuid);
    }

    public function testStoreBinaryContent()
    {
        $batchId = (string) Str::uuid();
        $exception = new Exception('message');

        $entries = collect([
            (new IncomingEntry(['message' => gzcompress('message')]))->batchId($batchId)->type(EntryType::LOG),
            (new IncomingExceptionEntry($exception, [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'message' => gzcompress($exception->getMessage()),
            ]))->batchId($batchId)->type(EntryType::EXCEPTION),
        ]);

        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $repository->store($entries);

        $entries->each(function ($entry) {
            $this->assertDatabaseMissing('telescope_entries', [
                'uuid' => $entry->uuid,
                'content' => false,
            ]);
        });
    }

    public function testStoreExceptionsOnlyUpdatesVisibleRows()
    {
        $exception = new Exception('repeated error');
        $batchId = (string) Str::uuid();

        $makeEntry = fn () => (new IncomingExceptionEntry($exception, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'message' => $exception->getMessage(),
        ]))->batchId($batchId)->type(EntryType::EXCEPTION);

        $repository = $this->app->make(DatabaseEntriesRepository::class);

        // Store the first occurrence.
        $repository->store(collect([$makeEntry()]));

        // Store a second occurrence — should hide the first.
        $repository->store(collect([$makeEntry()]));

        $entries = DB::table('telescope_entries')
            ->where('type', EntryType::EXCEPTION)
            ->get();

        $this->assertCount(2, $entries);
        $this->assertCount(1, $entries->where('should_display_on_index', true));
        $this->assertCount(1, $entries->where('should_display_on_index', false));

        // Store a third occurrence — should only update the one visible row, not both hidden ones.
        $repository->store(collect([$makeEntry()]));

        $entries = DB::table('telescope_entries')
            ->where('type', EntryType::EXCEPTION)
            ->get();

        $this->assertCount(3, $entries);
        $this->assertCount(1, $entries->where('should_display_on_index', true));
        $this->assertCount(2, $entries->where('should_display_on_index', false));
    }
}
