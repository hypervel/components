<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Algolia;

use Hypervel\Tests\Scout\Models\SearchableModel;
use Throwable;

/**
 * Integration tests for Scout console commands with Algolia.
 */
class AlgoliaCommandsIntegrationTest extends AlgoliaScoutIntegrationTestCase
{
    public function testImportCommandIndexesModels(): void
    {
        SearchableModel::withoutSyncingToSearch(function (): void {
            SearchableModel::create(['title' => 'First Document', 'body' => 'Content']);
            SearchableModel::create(['title' => 'Second Document', 'body' => 'Content']);
            SearchableModel::create(['title' => 'Third Document', 'body' => 'Content']);
        });

        $this->assertCount(3, SearchableModel::all());

        $this->artisan('scout:import', ['model' => SearchableModel::class])
            ->expectsOutputToContain('have been imported')
            ->assertOk();

        $index = (new SearchableModel)->searchableAs();
        $hits = $this->pollSearch($index, 'Document', 3);

        $this->assertCount(3, $hits);
    }

    public function testQueueImportCommandIndexesModels(): void
    {
        // Sync queue driver (testbench default) means MakeRangeSearchable
        // and the MakeSearchable jobs it dispatches both run inline, so by
        // the time the artisan call returns the writes have been issued.
        SearchableModel::withoutSyncingToSearch(function (): void {
            SearchableModel::create(['title' => 'First Document', 'body' => 'Content']);
            SearchableModel::create(['title' => 'Second Document', 'body' => 'Content']);
            SearchableModel::create(['title' => 'Third Document', 'body' => 'Content']);
        });

        $this->assertCount(3, SearchableModel::all());

        $this->artisan('scout:queue-import', ['model' => SearchableModel::class])
            ->expectsOutputToContain('have been queued')
            ->assertOk();

        $index = (new SearchableModel)->searchableAs();
        $hits = $this->pollSearch($index, 'Document', 3);

        $this->assertCount(3, $hits);
    }

    public function testFlushCommandRemovesModels(): void
    {
        SearchableModel::withoutSyncingToSearch(function (): void {
            SearchableModel::create(['title' => 'First', 'body' => 'Content']);
            SearchableModel::create(['title' => 'Second', 'body' => 'Content']);
        });

        $this->artisan('scout:import', ['model' => SearchableModel::class])->assertOk();
        $index = (new SearchableModel)->searchableAs();
        $this->pollSearch($index, '', 2);

        $this->artisan('scout:flush', ['model' => SearchableModel::class])->assertOk();

        $hits = $this->pollSearch($index, '', 0);
        $this->assertCount(0, $hits);
    }

    public function testDeleteIndexCommandRemovesIndex(): void
    {
        SearchableModel::withoutSyncingToSearch(function (): void {
            SearchableModel::create(['title' => 'One', 'body' => 'x']);
        });

        $this->artisan('scout:import', ['model' => SearchableModel::class])->assertOk();

        $index = (new SearchableModel)->searchableAs();
        $this->pollSearch($index, '', 1);

        $this->artisan('scout:delete-index', ['name' => $index])->assertOk();

        // After deletion the index should not appear in listIndices.
        $deadline = microtime(true) + 10;
        $stillThere = true;
        while (microtime(true) < $deadline) {
            $response = $this->algolia->listIndices();
            $names = collect($response['items'] ?? [])->pluck('name')->all();
            if (! in_array($index, $names, true)) {
                $stillThere = false;
                break;
            }
            usleep(200_000);
        }

        $this->assertFalse($stillThere, "Index {$index} still listed after delete-index command");
    }

    /**
     * Poll an Algolia index until the search returns the expected hit count.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pollSearch(string $index, string $query, int $expectedCount, int $timeoutMs = 15000): array
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $hits = [];

        while (microtime(true) < $deadline) {
            try {
                $response = $this->algolia->searchSingleIndex($index, ['query' => $query]);
                $hits = $response['hits'] ?? [];

                if (count($hits) === $expectedCount) {
                    return $hits;
                }
            } catch (Throwable) {
                // Index may not exist yet — keep polling until timeout.
            }

            usleep(200_000);
        }

        return $hits;
    }
}
