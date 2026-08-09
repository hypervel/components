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
use Hypervel\Telescope\Storage\EntryQueryOptions;
use Hypervel\Tests\Telescope\FeatureTestCase;

class DatabaseEntriesRepositoryTest extends FeatureTestCase
{
    public function testFindEntryByUuid(): void
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

    public function testUpdate(): void
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

    public function testUpdateSubstitutesInvalidUtf8(): void
    {
        $entry = EntryModelFactory::new()->create(['content' => ['existing' => true]]);
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $repository->update(collect([
            new EntryUpdate($entry->uuid, $entry->type, ['nested' => ['value' => "\xB1\x31"]]),
        ]));

        $content = json_decode(
            DB::table('telescope_entries')->where('uuid', $entry->uuid)->value('content'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($content['existing']);
        $this->assertSame("\u{FFFD}1", $content['nested']['value']);
    }

    public function testGetAppliesExplicitUuidFilters(): void
    {
        $requested = EntryModelFactory::new()->create();
        EntryModelFactory::new()->create();

        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $entries = $repository->get(null, (new EntryQueryOptions)->uuids([$requested->uuid])->limit(-1));

        $this->assertSame([$requested->uuid], $entries->pluck('id')->all());
        $this->assertTrue($repository->get(null, (new EntryQueryOptions)->uuids([])->limit(-1))->isEmpty());
    }

    public function testGetPreservesFalseyTagAndSequenceFilters(): void
    {
        $tagged = EntryModelFactory::new()->create();
        EntryModelFactory::new()->create();

        DB::table('telescope_entries_tags')->insert([
            'entry_uuid' => $tagged->uuid,
            'tag' => '0',
        ]);

        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $taggedEntries = $repository->get(null, (new EntryQueryOptions)->tag('0')->limit(-1));

        $this->assertSame([$tagged->uuid], $taggedEntries->pluck('id')->all());
        $this->assertTrue($repository->get(null, (new EntryQueryOptions)->beforeSequence(0)->limit(-1))->isEmpty());
        $this->assertCount(2, $repository->get(null, (new EntryQueryOptions)->tag('')->limit(-1)));
        $this->assertCount(2, $repository->get(null, (new EntryQueryOptions)->beforeSequence('')->limit(-1)));
    }

    public function testMonitorStoresEveryUniqueNewTag(): void
    {
        DB::table('telescope_monitoring')->insert(['tag' => 'existing']);

        $this->app->make(DatabaseEntriesRepository::class)->monitor([
            'existing',
            'first',
            'second',
            'first',
        ]);

        $this->assertSame(
            ['existing', 'first', 'second'],
            DB::table('telescope_monitoring')->orderBy('tag')->pluck('tag')->all(),
        );
    }

    public function testPruneOrdersDeletesToAvoidDeadlocks(): void
    {
        EntryModelFactory::new()->create(['created_at' => now()->subDays(2)]);

        $deletes = [];

        DB::listen(function ($query) use (&$deletes): void {
            if (str_starts_with($query->sql, 'delete')) {
                $deletes[] = $query->sql;
            }
        });

        $this->app->make(DatabaseEntriesRepository::class)->prune(now()->subDay(), false);

        $this->assertNotEmpty($deletes);

        foreach ($deletes as $sql) {
            $this->assertStringContainsString('order by', $sql);
        }
    }

    public function testClearOrdersDeletesToAvoidDeadlocks(): void
    {
        EntryModelFactory::new()->create();

        DB::table('telescope_monitoring')->insert([
            ['tag' => 'one'],
            ['tag' => 'two'],
        ]);

        $deletes = [];

        DB::listen(function ($query) use (&$deletes): void {
            if (str_starts_with($query->sql, 'delete')) {
                $deletes[] = $query->sql;
            }
        });

        $this->app->make(DatabaseEntriesRepository::class)->clear();

        $this->assertNotEmpty($deletes);

        foreach ($deletes as $sql) {
            $this->assertStringContainsString('order by', $sql);
        }
    }

    public function testStoreBinaryContent(): void
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

    public function testStoreExceptionsAggregatesFamiliesWithinEachChunk(): void
    {
        $batchId = (string) Str::uuid();
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $makeEntry = fn (string $file, int $line) => (new IncomingExceptionEntry(new Exception('error'), [
            'file' => $file,
            'line' => $line,
            'message' => 'error',
        ]))->batchId($batchId)->type(EntryType::EXCEPTION);

        $persisted = $makeEntry('first.php', 10);
        $repository->store(collect([$persisted]));

        $first = $makeEntry('first.php', 10);
        $other = $makeEntry('other.php', 20);
        $last = $makeEntry('first.php', 10);

        $repository->store(collect([$first, $other, $last]));

        $entries = DB::table('telescope_entries')
            ->where('type', EntryType::EXCEPTION)
            ->get()
            ->keyBy('uuid');

        $this->assertCount(4, $entries);
        $this->assertFalse((bool) $entries[$persisted->uuid]->should_display_on_index);
        $this->assertFalse((bool) $entries[$first->uuid]->should_display_on_index);
        $this->assertTrue((bool) $entries[$other->uuid]->should_display_on_index);
        $this->assertTrue((bool) $entries[$last->uuid]->should_display_on_index);

        $this->assertSame(1, json_decode($entries[$persisted->uuid]->content, true)['occurrences']);
        $this->assertSame(2, json_decode($entries[$first->uuid]->content, true)['occurrences']);
        $this->assertSame(1, json_decode($entries[$other->uuid]->content, true)['occurrences']);
        $this->assertSame(3, json_decode($entries[$last->uuid]->content, true)['occurrences']);
    }
}
