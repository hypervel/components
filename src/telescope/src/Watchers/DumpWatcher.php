<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Telescope\IncomingDumpEntry;
use Hypervel\Telescope\Telescope;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\VarDumper;
use Throwable;

class DumpWatcher extends Watcher
{
    protected const bool DEFAULT_INSTALLED = false;

    /**
     * Whether the Telescope dump handler is installed.
     */
    protected static bool $installed = self::DEFAULT_INSTALLED;

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
    public function register(Application $app): void
    {
        if (isset($_SERVER['VAR_DUMPER_FORMAT']) || static::$installed) {
            return;
        }

        $htmlDumper = new HtmlDumper;
        $htmlDumper->setDumpHeader('');

        $previous = VarDumper::setHandler(null);

        if ($previous === null) {
            return;
        }

        $handler = function (mixed $value, ?string $label = null) use ($htmlDumper, $previous): void {
            if (! $this->shouldRecordDump()) {
                $previous($value, $label);

                return;
            }

            $data = (new VarCloner)->cloneVar($value);

            if ($label !== null) {
                $data = $data->withContext(['label' => $label]);
            }

            $this->recordDump($htmlDumper->dump($data, true));
        };

        VarDumper::setHandler($handler);

        static::$installed = true;
    }

    /**
     * Determine if the dumped value should be recorded.
     */
    protected function shouldRecordDump(): bool
    {
        if ($this->options['always'] ?? false) {
            return true;
        }

        try {
            return (bool) $this->cache->store()->get('telescope:dump-watcher');
        } catch (Throwable) {
            return false;
        }
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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        VarDumper::setHandler(null);

        static::$installed = self::DEFAULT_INSTALLED;
    }
}
