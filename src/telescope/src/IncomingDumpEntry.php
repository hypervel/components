<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hyperf\Collection\Collection;

class IncomingDumpEntry extends IncomingEntry
{
    /**
     * Determine if the incoming entry is an exception.
     */
    public function isDump(): bool
    {
        return true;
    }

    /**
     * Assign entry point parameters from the given batch entries.
     */
    public function assignEntryPointFromBatch(array $batch): void
    {
        $entryPoint = Collection::make($batch)->first(function ($entry) {
            return in_array($entry->type, [EntryType::REQUEST, EntryType::JOB, EntryType::COMMAND]);
        });

        if (! $entryPoint) {
            return;
        }

        $this->content = array_merge($this->content, [
            'entry_point_type' => $entryPoint->type,
            'entry_point_uuid' => $entryPoint->uuid,
            'entry_point_description' => $this->entryPointDescription($entryPoint),
        ]);
    }

    /**
     * Description for the entry point.
     */
    private function entryPointDescription(IncomingEntry $entryPoint): string
    {
        switch ($entryPoint->type) {
            case EntryType::REQUEST:
                return $entryPoint->content['method'] . ' ' . $entryPoint->content['uri'];
            case EntryType::JOB:
                return $entryPoint->content['name'];
            case EntryType::COMMAND:
                return $entryPoint->content['command'];
        }

        return '';
    }
}
