<?php

declare(strict_types=1);

namespace Hypervel\Cache\Support;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Telescope\Telescope;

/**
 * Detects monitoring and profiling tools that affect benchmark accuracy.
 *
 * These tools intercept cache operations, adding overhead that skews results
 * and consuming memory that can cause out-of-memory errors during benchmarks.
 */
class MonitoringDetector
{
    public function __construct(
        private readonly ConfigInterface $config,
    ) {}

    /**
     * Detect active monitoring/profiling tools.
     *
     * @return array<string, string> Tool name => how to disable
     */
    public function detect(): array
    {
        $detected = [];

        // Hypervel Telescope
        if (class_exists(Telescope::class) && $this->config->get('telescope.enabled')) {
            $detected['Hypervel Telescope'] = 'TELESCOPE_ENABLED=false';
        }

        // Xdebug (when not in 'off' mode)
        if (extension_loaded('xdebug')) {
            $mode = ini_get('xdebug.mode') ?: 'off';

            if ($mode !== 'off') {
                $detected['Xdebug (mode: ' . $mode . ')'] = 'xdebug.mode=off or disable extension';
            }
        }

        // Blackfire
        if (extension_loaded('blackfire')) {
            $detected['Blackfire'] = 'disable blackfire extension';
        }

        return $detected;
    }
}
