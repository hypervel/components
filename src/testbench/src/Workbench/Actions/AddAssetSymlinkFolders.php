<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Workbench\Actions;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Testbench\Contracts\Config as ConfigContract;

use function Hypervel\Testbench\is_symlink;
use function Hypervel\Testbench\package_path;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
final class AddAssetSymlinkFolders
{
    /**
     * Construct a new action.
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly ConfigContract $config,
    ) {
    }

    /**
     * Execute the action.
     */
    public function handle(): void
    {
        /** @var array<int, array{from: string, to: string, reverse?: bool}> $sync */
        $sync = $this->config->getWorkbenchAttributes()['sync'];

        (new Collection($sync))
            ->map(function ($pair) {
                /** @var bool $reverse */
                $reverse = $pair['reverse'] ?? false;

                /** @var string $from */
                $from = $reverse === false ? package_path($pair['from']) : base_path($pair['from']);

                /** @var string $to */
                $to = $reverse === false ? base_path($pair['to']) : package_path($pair['to']);

                return $this->files->isDirectory($from)
                    ? ['from' => $from, 'to' => $to]
                    : null;
            })->filter()
            ->each(function ($pair) {
                /** @var array{from: string, to: string} $pair */

                /** @var string $from */
                $from = $pair['from'];

                /** @var string $to */
                $to = $pair['to'];

                if (is_symlink($to)) {
                    windows_os() ? @rmdir($to) : $this->files->delete($to);
                } elseif ($this->files->isDirectory($to)) {
                    $this->files->deleteDirectory($to);
                }

                /** @var string $rootDirectory */
                $rootDirectory = Str::beforeLast($to, '/');

                if (! $this->files->isDirectory($rootDirectory)) {
                    $this->files->ensureDirectoryExists($rootDirectory);
                }

                $this->files->link($from, $to);
            });
    }
}
