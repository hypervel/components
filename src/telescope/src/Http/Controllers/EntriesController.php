<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Telescope\Contracts\ClearableRepository;

class EntriesController
{
    /**
     * Delete all of the entries from storage.
     */
    public function destroy(ClearableRepository $storage): array
    {
        $storage->clear();

        return [
            'success' => true,
        ];
    }
}
