<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Inertia\DevTools\EntriesRepository;
use Hypervel\Testing\ParallelTesting;

/**
 * Binds a real file-backed EntriesRepository over a throwaway temp directory, so devtools
 * tests exercise the actual storage rather than an in-memory double that could drift from it.
 */
trait InteractsWithDevToolsStorage
{
    protected EntriesRepository $repo;

    protected string $devtoolsStoragePath;

    /**
     * Bind a repository over a fresh temporary storage directory.
     */
    protected function bindEntriesRepository(): void
    {
        $this->devtoolsStoragePath = ParallelTesting::tempDir('InertiaDevTools');

        $this->clearDevToolsStorage();

        $this->repo = new EntriesRepository(path: $this->devtoolsStoragePath, autoPruneHours: 24);

        $this->app->instance(EntriesRepository::class, $this->repo);
    }

    /**
     * Delete the temporary storage directory.
     */
    protected function clearDevToolsStorage(): void
    {
        if (isset($this->devtoolsStoragePath)) {
            (new Filesystem)->deleteDirectory($this->devtoolsStoragePath);
        }
    }

    /**
     * Recorded entry metadata, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function recordedEntries(): array
    {
        return $this->repo->all();
    }

    /**
     * The full payload of the most recently recorded entry.
     *
     * @return null|array<string, mixed>
     */
    protected function latestRecordedEntry(): ?array
    {
        $metas = $this->recordedEntries();

        return $metas === [] ? null : $this->repo->get($metas[0]['id']);
    }
}
