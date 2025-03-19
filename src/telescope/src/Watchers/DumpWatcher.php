<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Exception;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Telescope\IncomingDumpEntry;
use Hypervel\Telescope\Telescope;
use Psr\Container\ContainerInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\VarDumper;

class DumpWatcher extends Watcher
{
    /**
     * Create a new watcher instance.
     */
    public function __construct(
        protected CacheFactory $cache,
        array $options = []
    ) {
        parent::__construct($options);
    }

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $dumpWatcherCache = false;

        try {
            /* @phpstan-ignore-next-line */
            $dumpWatcherCache = $this->cache->get('telescope:dump-watcher');
        } catch (Exception) {
        }

        if (! ($this->options['always'] ?? false) && ! $dumpWatcherCache) {
            return;
        }

        $htmlDumper = new HtmlDumper();
        $htmlDumper->setDumpHeader('');

        VarDumper::setHandler(function ($var) use ($htmlDumper) {
            $this->recordDump($htmlDumper->dump(
                (new VarCloner())->cloneVar($var),
                true
            ));
        });
    }

    /**
     * Record a dumped variable.
     */
    public function recordDump(string $dump): void
    {
        Telescope::recordDump(
            IncomingDumpEntry::make(['dump' => $dump])
        );
    }
}
