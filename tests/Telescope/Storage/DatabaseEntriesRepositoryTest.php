<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Storage;

use Exception;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Json;
use Hypervel\Support\Str;
use Hypervel\Telescope\Database\Factories\EntryModelFactory;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\EntryUpdate;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\IncomingExceptionEntry;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
use Hypervel\Telescope\Storage\EntryQueryOptions;
use Hypervel\Tests\Telescope\FeatureTestCase;
use JsonException;
use TypeError;

class DatabaseEntriesRepositoryTest extends FeatureTestCase
{
    public function testConfigurationCanBeReloaded(): void
    {
        $repository = new class('initial', 25) extends DatabaseEntriesRepository {
            public function connection(): string
            {
                return $this->connection;
            }

            public function chunkSize(): int
            {
                return $this->chunkSize;
            }
        };

        $this->assertSame('initial', $repository->connection());
        $this->assertSame(25, $repository->chunkSize());

        $repository->setConnection('refreshed');
        $repository->setChunkSize(null);

        $this->assertSame('refreshed', $repository->connection());
        $this->assertSame(1000, $repository->chunkSize());

        $repository->setChunkSize(0);

        $this->assertSame(1000, $repository->chunkSize());
    }

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

    public function testNormalAndExceptionEntriesRoundTripAtTheMaximumNestingDepth(): void
    {
        $batchId = (string) Str::uuid();
        $normal = (new IncomingEntry(['nested' => $this->nestedValue(511)]))
            ->batchId($batchId)
            ->type(EntryType::LOG);
        $exception = $this->exceptionEntry($batchId, 511);
        $expectedNormalContent = $normal->content;
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $repository->store(collect([$normal, $exception]));

        $normalContent = Json::decode(
            DB::table('telescope_entries')->where('uuid', $normal->uuid)->value('content'),
        );
        $exceptionContent = Json::decode(
            DB::table('telescope_entries')->where('uuid', $exception->uuid)->value('content'),
        );

        $this->assertSame($expectedNormalContent, $normalContent);
        $this->assertSame($exception->content['nested'], $exceptionContent['nested']);
        $this->assertSame(1, $exceptionContent['occurrences']);
    }

    public function testStorePurgesEveryTopLevelFieldOverTheMaximumNestingDepth(): void
    {
        $batchId = (string) Str::uuid();
        $entry = (new IncomingEntry([
            'first' => $this->nestedValue(512),
            'second' => $this->nestedValue(512),
            'safe' => 'retained',
        ]))
            ->batchId($batchId)
            ->type(EntryType::LOG)
            ->tags(['deep']);
        $sibling = (new IncomingEntry(['message' => 'stored']))
            ->batchId($batchId)
            ->type(EntryType::LOG)
            ->tags(['sibling']);
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $repository->store(collect([$entry, $sibling]));

        $content = Json::decode(
            DB::table('telescope_entries')->where('uuid', $entry->uuid)->value('content'),
        );

        $this->assertSame('Purged By Telescope', $content['first']);
        $this->assertSame('Purged By Telescope', $content['second']);
        $this->assertSame('retained', $content['safe']);
        $this->assertDatabaseHas('telescope_entries', ['uuid' => $sibling->uuid]);
        $this->assertDatabaseHas('telescope_entries_tags', ['entry_uuid' => $entry->uuid, 'tag' => 'deep']);
        $this->assertDatabaseHas('telescope_entries_tags', ['entry_uuid' => $sibling->uuid, 'tag' => 'sibling']);
    }

    public function testStoreRethrowsNonDepthErrorsFoundAfterDepthRecoveryStarts(): void
    {
        // Depth must fail first so the field-level retry exposes the later INF/NAN error.
        $entry = (new IncomingEntry([
            'deep' => $this->nestedValue(512),
            'invalid' => INF,
        ]))->type(EntryType::LOG);
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        try {
            $repository->store(collect([$entry]));
            $this->fail('Expected the non-depth JSON encoding error to be rethrown.');
        } catch (JsonException $exception) {
            $this->assertSame(JSON_ERROR_INF_OR_NAN, $exception->getCode());
        }

        $this->assertDatabaseMissing('telescope_entries', ['uuid' => $entry->uuid]);
    }

    public function testExceptionPurgesDeepContextWithoutLosingFamilyStateOrTags(): void
    {
        $batchId = (string) Str::uuid();
        $exception = $this->exceptionEntry($batchId, 512)->tags(['deep']);
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $repository->store(collect([$exception]));

        $row = DB::table('telescope_entries')->where('uuid', $exception->uuid)->first();
        $content = Json::decode($row->content);

        $this->assertSame('error', $content['message']);
        $this->assertSame('Purged By Telescope', $content['nested']);
        $this->assertSame(1, $content['occurrences']);
        $this->assertTrue((bool) $row->should_display_on_index);
        $this->assertDatabaseHas('telescope_entries_tags', ['entry_uuid' => $exception->uuid, 'tag' => 'deep']);
    }

    public function testUnencodableExceptionLeavesThePreviousFamilyEntryVisibleAndStopsTheBatch(): void
    {
        $batchId = (string) Str::uuid();
        $repository = $this->app->make(DatabaseEntriesRepository::class);
        $persisted = $this->exceptionEntry($batchId, 1)->tags(['persisted']);
        $repository->store(collect([$persisted]));

        $original = DB::table('telescope_entries')->where('uuid', $persisted->uuid)->first();
        $error = new Exception('error');
        $invalid = (new IncomingExceptionEntry($error, [
            'file' => 'same.php',
            'line' => 10,
            'message' => 'error',
            'invalid' => INF,
        ]))->batchId($batchId)->type(EntryType::EXCEPTION)->tags(['invalid']);
        $ordinary = (new IncomingEntry(['message' => 'not stored']))
            ->batchId($batchId)
            ->type(EntryType::LOG)
            ->tags(['ordinary']);

        try {
            $repository->store(collect([$invalid, $ordinary]));
            $this->fail('Expected the non-depth JSON encoding error to be rethrown.');
        } catch (JsonException $exception) {
            $this->assertSame(JSON_ERROR_INF_OR_NAN, $exception->getCode());
        }

        $retained = DB::table('telescope_entries')->where('uuid', $persisted->uuid)->first();

        $this->assertSame($original->content, $retained->content);
        $this->assertTrue((bool) $retained->should_display_on_index);
        $this->assertDatabaseMissing('telescope_entries', ['uuid' => $invalid->uuid]);
        $this->assertDatabaseMissing('telescope_entries', ['uuid' => $ordinary->uuid]);
        $this->assertDatabaseMissing('telescope_entries_tags', ['entry_uuid' => $invalid->uuid]);
        $this->assertDatabaseMissing('telescope_entries_tags', ['entry_uuid' => $ordinary->uuid]);
    }

    public function testUpdatePreservesMaximumDepthContentWhileMergingChanges(): void
    {
        $entry = EntryModelFactory::new()->create(['content' => $this->nestedValue(512)]);
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $repository->update(collect([
            new EntryUpdate($entry->uuid, $entry->type, ['updated' => true]),
        ]));

        $content = Json::decode(
            DB::table('telescope_entries')->where('uuid', $entry->uuid)->value('content'),
        );

        $this->assertTrue($content['updated']);
        unset($content['updated']);
        $this->assertSame($entry->content, $content);
    }

    public function testUpdatePurgesDeepFieldsAndContinuesWithLaterUpdates(): void
    {
        $deep = EntryModelFactory::new()->create(['content' => ['existing' => true]]);
        $later = EntryModelFactory::new()->create(['content' => ['existing' => true]]);
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        $failedUpdates = $repository->update(collect([
            (new EntryUpdate($deep->uuid, $deep->type, ['nested' => $this->nestedValue(512)]))
                ->addTags(['updated']),
            new EntryUpdate($later->uuid, $later->type, ['later' => true]),
        ]));

        $deepContent = Json::decode(
            DB::table('telescope_entries')->where('uuid', $deep->uuid)->value('content'),
        );
        $laterContent = Json::decode(
            DB::table('telescope_entries')->where('uuid', $later->uuid)->value('content'),
        );

        $this->assertTrue($failedUpdates->isEmpty());
        $this->assertTrue($deepContent['existing']);
        $this->assertSame('Purged By Telescope', $deepContent['nested']);
        $this->assertTrue($laterContent['later']);
        $this->assertDatabaseHas('telescope_entries_tags', ['entry_uuid' => $deep->uuid, 'tag' => 'updated']);
    }

    public function testUpdateRejectsMalformedAndWrongShapeContentWithoutChangingStoredBytes(): void
    {
        $repository = $this->app->make(DatabaseEntriesRepository::class);

        foreach (['{invalid' => JsonException::class, 'null' => TypeError::class] as $content => $exceptionClass) {
            $entry = EntryModelFactory::new()->create();
            DB::table('telescope_entries')->where('uuid', $entry->uuid)->update(['content' => $content]);
            $update = new EntryUpdate($entry->uuid, $entry->type, ['updated' => true]);

            $this->assertThrows(
                fn () => $repository->update(collect([$update])),
                $exceptionClass,
            );

            $this->assertSame(
                $content,
                DB::table('telescope_entries')->where('uuid', $entry->uuid)->value('content'),
            );
        }
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

    private function exceptionEntry(string $batchId, int $nestedDepth): IncomingExceptionEntry
    {
        $exception = new Exception('error');

        return (new IncomingExceptionEntry($exception, [
            'file' => 'same.php',
            'line' => 10,
            'message' => 'error',
            'nested' => $this->nestedValue($nestedDepth),
        ]))->batchId($batchId)->type(EntryType::EXCEPTION);
    }

    private function nestedValue(int $depth): array
    {
        $value = 'leaf';

        for ($index = 0; $index < $depth; ++$index) {
            $value = ['value' => $value];
        }

        return $value;
    }
}
