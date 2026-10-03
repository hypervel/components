<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Closure;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Inertia\DevTools\EntriesRepository;
use Hypervel\Support\Str;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Inertia\TestCase;
use InvalidArgumentException;
use RuntimeException;

class EntriesRepositoryTest extends TestCase
{
    protected string $storagePath;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = ParallelTesting::tempDir('InertiaDevToolsEntriesRepository');

        (new Filesystem)->deleteDirectory($this->storagePath);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storagePath);

        parent::tearDown();
    }

    /**
     * Create a repository over the temporary storage directory.
     */
    protected function makeRepository(): EntriesRepository
    {
        return new EntriesRepository(
            path: $this->storagePath,
            autoPruneHours: 24,
        );
    }

    /**
     * Build an entry payload with the given metadata overrides.
     *
     * @param array<string, mixed> $metaOverrides
     * @return array<string, mixed>
     */
    protected function envelope(array $metaOverrides = []): array
    {
        $id = $metaOverrides['id'] ?? (string) Str::ulid();

        return [
            '__meta' => array_merge([
                'id' => $id,
                'tabUuid' => 'tab-a',
                'batchId' => null,
                'timestamp' => '2026-05-12T10:00:00.000Z',
                'utime' => microtime(true),
                'method' => 'GET',
                'url' => 'http://app.test/users',
                'component' => 'Users/Index',
                'requestType' => 'navigate',
                'status' => 200,
                'serverTimingMs' => 1.5,
            ], $metaOverrides),
            'http' => ['requestHeaders' => [], 'responseHeaders' => [], 'requestBody' => null, 'responseBody' => null],
            'props' => [],
            'propValues' => [],
            'route' => ['name' => null, 'uri' => '', 'action' => null],
        ];
    }

    public function testSaveAndGetRoundTrip(): void
    {
        $repo = $this->makeRepository();
        $payload = $this->envelope();
        $id = $payload['__meta']['id'];

        $repo->save($id, $payload);

        $this->assertSame($payload, $repo->get($id));
    }

    public function testGetReturnsNullForMissingEntry(): void
    {
        $repo = $this->makeRepository();

        $this->assertNull($repo->get('does-not-exist'));
    }

    public function testSaveRejectsInvalidEntryIds(): void
    {
        $repo = $this->makeRepository();

        $this->expectException(InvalidArgumentException::class);

        $repo->save('../secret', $this->envelope(['id' => '../secret']));
    }

    public function testGitignoreIsWrittenOnFirstSave(): void
    {
        $repo = $this->makeRepository();
        $payload = $this->envelope();
        $id = $payload['__meta']['id'];

        $repo->save($id, $payload);

        $gitignore = $this->storagePath . DIRECTORY_SEPARATOR . '.gitignore';

        $this->assertFileExists($gitignore);
        $this->assertSame("*\n", file_get_contents($gitignore));
    }

    public function testSaveUpdatesMetaIndexForHotPathLookups(): void
    {
        $repo = $this->makeRepository();
        $payload = $this->envelope();

        $repo->save($payload['__meta']['id'], $payload);

        $metaPath = $this->storagePath . DIRECTORY_SEPARATOR . '_meta.json';

        $this->assertFileExists($metaPath);
        $index = json_decode((string) file_get_contents($metaPath), true);

        $this->assertIsArray($index);
        $this->assertSame($payload['__meta']['id'], $index[$payload['__meta']['id']]['id']);
        $this->assertSame($payload['__meta']['tabUuid'], $index[$payload['__meta']['id']]['tabUuid']);
    }

    public function testAllRebuildsTheIndexWhenItIsCorrupt(): void
    {
        $repo = $this->makeRepository();
        $payload = $this->envelope();
        $repo->save($payload['__meta']['id'], $payload);

        file_put_contents($this->storagePath . DIRECTORY_SEPARATOR . '_meta.json', '{ not valid json');

        $found = $repo->all();

        $this->assertCount(1, $found);
        $this->assertSame($payload['__meta']['id'], $found[0]['id']);
    }

    public function testACorruptIndexDoesNotDropPriorEntriesOnTheNextSave(): void
    {
        $repo = $this->makeRepository();
        $first = $this->envelope();
        $repo->save($first['__meta']['id'], $first);

        file_put_contents($this->storagePath . DIRECTORY_SEPARATOR . '_meta.json', 'garbage');

        $second = $this->envelope();
        $repo->save($second['__meta']['id'], $second);

        $ids = array_column($repo->all(), 'id');

        $this->assertContains($first['__meta']['id'], $ids);
        $this->assertContains($second['__meta']['id'], $ids);
    }

    public function testAMissingIndexDoesNotDropPriorEntriesOnTheNextSave(): void
    {
        $repo = $this->makeRepository();
        $old = $this->envelope(['utime' => microtime(true) - (48 * 3600)]);
        $repo->save($old['__meta']['id'], $old);

        unlink($this->storagePath . DIRECTORY_SEPARATOR . '_meta.json');

        $fresh = $this->envelope();
        $repo->save($fresh['__meta']['id'], $fresh);

        $ids = array_column($repo->all(), 'id');

        $this->assertContains($old['__meta']['id'], $ids);
        $this->assertContains($fresh['__meta']['id'], $ids);

        $repo->prune(24);

        $this->assertNull($repo->get($old['__meta']['id']));
        $this->assertNotNull($repo->get($fresh['__meta']['id']));
    }

    public function testIndexRecoveryKeepsAnEntrySavedSinceTheIndexWasRead(): void
    {
        $first = $this->envelope();
        $this->makeRepository()->save($first['__meta']['id'], $first);

        unlink($this->storagePath . DIRECTORY_SEPARATOR . '_meta.json');

        $second = $this->envelope();

        // Another request saves an entry after this repository found the index missing,
        // but before its recovery takes the index lock.
        $recovering = new ConcurrentSaveEntriesRepository(
            $this->storagePath,
            function () use ($second): void {
                $this->makeRepository()->save($second['__meta']['id'], $second);
            },
        );

        $recovered = array_column($recovering->all(), 'id');
        $listed = array_column($this->makeRepository()->all(), 'id');

        foreach ([$recovered, $listed] as $ids) {
            $this->assertContains($first['__meta']['id'], $ids);
            $this->assertContains($second['__meta']['id'], $ids);
        }
    }

    public function testSaveFailsWithoutWritingTheEntryWhenTheIndexCannotBeOpened(): void
    {
        mkdir($this->storagePath . DIRECTORY_SEPARATOR . '_meta.json', 0700, true);
        $entry = $this->envelope();

        $this->assertThrows(
            function () use ($entry): void {
                $this->makeRepository()->save($entry['__meta']['id'], $entry);
            },
            RuntimeException::class,
            'Unable to open the Inertia DevTools index',
        );

        // The index would never list or prune an entry file written by the failed save.
        $this->assertFileDoesNotExist($this->storagePath . DIRECTORY_SEPARATOR . $entry['__meta']['id'] . '.json');
    }

    public function testAllReturnsEveryEntrySortedDescendingById(): void
    {
        $repo = $this->makeRepository();

        $ids = [(string) Str::ulid(), (string) Str::ulid(), (string) Str::ulid()];
        sort($ids);

        foreach ($ids as $id) {
            $payload = $this->envelope(['id' => $id]);
            $repo->save($id, $payload);
        }

        $found = $repo->all();

        $expected = array_reverse($ids);

        $this->assertSame($expected, array_column($found, 'id'));
    }

    public function testEnforceTabLimitDropsOldestEntries(): void
    {
        $repo = $this->makeRepository();

        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            $repo->save($id, $this->envelope(['id' => $id, 'tabUuid' => 'tab-a']));
        }

        sort($ids);

        $repo->enforceTabLimit('tab-a', 2);

        $remaining = array_column($repo->all(), 'id');
        sort($remaining);

        $this->assertSame(array_slice($ids, -2), $remaining);
    }

    public function testEnforceTabLimitLimitsEntriesWithoutATabAsOneGroup(): void
    {
        $repo = $this->makeRepository();

        $untabbed = [];
        for ($i = 0; $i < 3; ++$i) {
            $id = (string) Str::ulid();
            $untabbed[] = $id;
            $repo->save($id, $this->envelope(['id' => $id, 'tabUuid' => null]));
        }

        $tabbed = $this->envelope(['tabUuid' => 'tab-a']);
        $repo->save($tabbed['__meta']['id'], $tabbed);

        sort($untabbed);

        $repo->enforceTabLimit(null, 2);

        $remaining = array_column($repo->all(), 'id');
        sort($remaining);

        $expected = [...array_slice($untabbed, -2), $tabbed['__meta']['id']];
        sort($expected);

        $this->assertSame($expected, $remaining);
    }

    public function testPruneDropsEntriesOlderThanCutoff(): void
    {
        $repo = $this->makeRepository();
        $old = $this->envelope(['utime' => microtime(true) - (48 * 3600)]);
        $fresh = $this->envelope();

        $repo->save($old['__meta']['id'], $old);
        $repo->save($fresh['__meta']['id'], $fresh);

        $repo->prune(24);

        $this->assertNull($repo->get($old['__meta']['id']));
        $this->assertNotNull($repo->get($fresh['__meta']['id']));
    }

    public function testPruneIfDueSkipsUntilIntervalElapsed(): void
    {
        config()->set('inertia.devtools.storage.prune_interval', 300);

        $repo = $this->makeRepository();
        $old = $this->envelope(['utime' => microtime(true) - (48 * 3600)]);

        $repo->save($old['__meta']['id'], $old);
        $repo->pruneIfDue();

        $this->assertNull($repo->get($old['__meta']['id']));

        // Expired too, so it survives only because the prune is skipped.
        $skipped = $this->envelope(['utime' => microtime(true) - (48 * 3600)]);
        $repo->save($skipped['__meta']['id'], $skipped);
        file_put_contents($this->storagePath . DIRECTORY_SEPARATOR . '_last_prune', (string) time(), LOCK_EX);
        $repo->pruneIfDue();

        $this->assertNotNull($repo->get($skipped['__meta']['id']));
    }
}

class ConcurrentSaveEntriesRepository extends EntriesRepository
{
    /**
     * Create a new repository instance that runs the callback before its next index lock.
     */
    public function __construct(string $path, private ?Closure $beforeIndexLock)
    {
        parent::__construct(path: $path);
    }

    /**
     * Run the pending callback once, then apply the change under the index lock.
     */
    protected function mutateIndex(callable $mutator): void
    {
        if ($this->beforeIndexLock !== null) {
            $callback = $this->beforeIndexLock;
            $this->beforeIndexLock = null;

            $callback();
        }

        parent::mutateIndex($mutator);
    }
}
