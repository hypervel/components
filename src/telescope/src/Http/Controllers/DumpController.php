<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Http\Request;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryQueryOptions;
use Hypervel\Telescope\Watchers\DumpWatcher;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

class DumpController extends EntryController
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected CacheFactory $cache
    ) {
    }

    /**
     * List the entries of the given type.
     */
    public function index(Request $request, EntriesRepository $storage): array
    {
        /* @phpstan-ignore-next-line */
        $this->cache->put('telescope:dump-watcher', true, now()->addSeconds(4));

        return [
            'dump' => (new HtmlDumper())->dump((new VarCloner())->cloneVar(true), true),
            'entries' => $storage->get(
                $this->entryType(),
                EntryQueryOptions::fromRequest($request)
            ),
            'status' => $this->status(),
        ];
    }

    /**
     * Determine the watcher recording status.
     */
    protected function status(): string
    {
        /* @phpstan-ignore-next-line */
        if ($this->cache->getStore() instanceof ArrayStore) {
            return 'wrong-cache';
        }

        return parent::status();
    }

    /**
     * The entry type for the controller.
     */
    protected function entryType(): string
    {
        return EntryType::DUMP;
    }

    /**
     * The watcher class for the controller.
     */
    protected function watcher(): string
    {
        return DumpWatcher::class;
    }
}
