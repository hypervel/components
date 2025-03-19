<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\ViewWatcher;

class ViewsController extends EntryController
{
    /**
     * The entry type for the controller.
     */
    protected function entryType(): string
    {
        return EntryType::VIEW;
    }

    /**
     * The watcher class for the controller.
     */
    protected function watcher(): string
    {
        return ViewWatcher::class;
    }
}
