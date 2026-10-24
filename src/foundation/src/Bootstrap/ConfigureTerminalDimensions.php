<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application;
use ReflectionProperty;
use Symfony\Component\Console\Terminal;

/**
 * Prevent Symfony Terminal from repeatedly spawning `stty` when headless.
 *
 * Symfony does not cache failed dimension probes, so each lookup otherwise
 * enters Swoole's hooked subprocess wait, which can hang even after stty exits.
 *
 * @see https://github.com/swoole/swoole-src/issues/6328
 */
class ConfigureTerminalDimensions
{
    /**
     * Configure deterministic dimensions for a headless terminal.
     */
    public function bootstrap(Application $app): void
    {
        static::configure();
    }

    /**
     * Configure headless fallback dimensions without modifying the environment.
     */
    public static function configure(): void
    {
        if (defined('STDOUT') && stream_isatty(STDOUT)) {
            return;
        }

        // Symfony has no dimension setter. Fill its cache so defaults cannot mask
        // later environment loads; getWidth()/getHeight() still prefer explicit values.
        foreach (['width' => 80, 'height' => 24] as $name => $default) {
            $property = new ReflectionProperty(Terminal::class, $name);

            if ($property->getValue() === null) {
                $property->setValue(null, $default);
            }
        }
    }
}
