<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Foundation\Console\VendorPublishCommand as Command;

use function Hypervel\Testbench\transform_realpath_to_relative;

class VendorPublishCommand extends Command
{
    /**
     * Write a status message to the console.
     */
    protected function status(string $from, string $to, string $type): void
    {
        $format = function (string $path) use ($type): string {
            if ($type === 'directory' && is_link($path)) {
                return $path;
            }

            $realPath = realpath($path);

            if ($realPath !== false) {
                $path = $realPath;
            }

            return match (true) {
                $this->files->exists($path) => $path,
                default => (string) realpath($path),
            };
        };

        $this->components->task(sprintf(
            'Copying %s [%s] to [%s]',
            $type,
            transform_realpath_to_relative($format($from)),
            transform_realpath_to_relative($format($to)),
        ));
    }
}
