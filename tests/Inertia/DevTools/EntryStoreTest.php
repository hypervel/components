<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Inertia\DevTools\Data\IncomingEntry;
use Hypervel\Inertia\DevTools\EntriesRepository;
use Hypervel\Inertia\DevTools\EntryStore;
use Hypervel\Support\Facades\Log;
use Hypervel\Tests\Inertia\TestCase;
use RuntimeException;

class EntryStoreTest extends TestCase
{
    public function testFlushPersistsThePendingEntryPayload(): void
    {
        $recorder = new EntryStore;
        $entry = new IncomingEntry;
        $entry->tabUuid = 'tab-a';
        $entry->component = 'Users/Index';

        $repo = new RecorderSpyRepo;

        $recorder->record($entry);
        $recorder->flush($repo);

        $this->assertCount(1, $repo->saved);
        $this->assertSame($entry->id, array_key_first($repo->saved));
        $this->assertSame('Users/Index', $repo->saved[$entry->id]['__meta']['component']);
    }

    public function testFlushPrunesAfterPersistingTheEntry(): void
    {
        $recorder = new EntryStore;
        $repo = new RecorderSpyRepo;

        $recorder->record(new IncomingEntry);
        $recorder->flush($repo);

        $this->assertSame(1, $repo->pruneCalls);
    }

    public function testFlushWithoutAPendingEntryLeavesRepositoryUnchanged(): void
    {
        $recorder = new EntryStore;
        $repo = new RecorderSpyRepo;

        $recorder->flush($repo);

        $this->assertSame([], $repo->saved);
        $this->assertSame([], $repo->tabLimitCalls);
        $this->assertSame(0, $repo->pruneCalls);
    }

    public function testFlushEnforcesTheConfiguredTabLimitForTabbedEntries(): void
    {
        config()->set('inertia.devtools.storage.limit', 12);

        $recorder = new EntryStore;
        $entry = new IncomingEntry;
        $entry->tabUuid = 'tab-a';
        $repo = new RecorderSpyRepo;

        $recorder->record($entry);
        $recorder->flush($repo);

        $this->assertSame([['tabUuid' => 'tab-a', 'limit' => 12]], $repo->tabLimitCalls);
    }

    public function testFlushSkipsTabLimitEnforcementForEntriesWithoutTabId(): void
    {
        config()->set('inertia.devtools.storage.limit', 12);

        $recorder = new EntryStore;
        $repo = new RecorderSpyRepo;

        $recorder->record(new IncomingEntry);
        $recorder->flush($repo);

        $this->assertSame([], $repo->tabLimitCalls);
    }

    public function testCircuitBreakerSuppressesAfterRepositoryError(): void
    {
        Log::shouldReceive('warning')->once();

        $recorder = new EntryStore;
        $failing = new FailingEntriesRepository;

        $first = new IncomingEntry;
        $recorder->record($first);
        $recorder->flush($failing);

        $second = new IncomingEntry;
        $recorder->record($second);
        $recorder->flush($failing);

        $this->assertSame(1, $failing->calls, 'Second flush should be suppressed by circuit breaker.');
    }
}

/**
 * In-memory spy over the real repository, so the EntryStore can be unit-tested without disk I/O.
 */
class RecorderSpyRepo extends EntriesRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $saved = [];

    /** @var array<int, array{tabUuid: string, limit: int}> */
    public array $tabLimitCalls = [];

    public int $pruneCalls = 0;

    /**
     * Create a new spy repository instance without a storage directory.
     */
    public function __construct()
    {
    }

    /**
     * Record the saved entry data.
     *
     * @param array<string, mixed> $data
     */
    public function save(string $id, array $data): void
    {
        $this->saved[$id] = $data;
    }

    /**
     * Record the tab limit enforcement.
     */
    public function enforceTabLimit(string $tabUuid, int $limit): void
    {
        $this->tabLimitCalls[] = ['tabUuid' => $tabUuid, 'limit' => $limit];
    }

    /**
     * Count the prune check.
     */
    public function pruneIfDue(): void
    {
        ++$this->pruneCalls;
    }
}

// Local failing stub for the circuit-breaker test below.
class FailingEntriesRepository extends EntriesRepository
{
    public int $calls = 0;

    /**
     * Create a new failing repository instance without a storage directory.
     */
    public function __construct()
    {
    }

    /**
     * Count the save attempt and fail it.
     *
     * @param array<string, mixed> $data
     */
    public function save(string $id, array $data): void
    {
        ++$this->calls;

        throw new RuntimeException('disk full');
    }
}
