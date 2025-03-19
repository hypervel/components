<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryQueryOptions;
use Hypervel\Telescope\Watchers\JobWatcher;

class QueueController extends EntryController
{
    /**
     * The entry type for the controller.
     */
    protected function entryType(): string
    {
        return EntryType::JOB;
    }

    /**
     * The watcher class for the controller.
     */
    protected function watcher(): string
    {
        return JobWatcher::class;
    }

    /**
     * Get an entry with the given ID.
     */
    public function show(EntriesRepository $storage, string $id): array
    {
        $entry = $storage->find($id);

        return [
            'entry' => $entry,
            'batch' => isset($entry->content['updated_batch_id'])
            ? $storage->get(null, EntryQueryOptions::forBatchId($entry->content['updated_batch_id']))
            : null,
        ];
    }
}
