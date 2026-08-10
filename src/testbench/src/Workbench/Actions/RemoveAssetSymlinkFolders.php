<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Workbench\Actions;

use Closure;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use RuntimeException;

use function Hypervel\Testbench\is_symlink;
use function Hypervel\Testbench\package_path;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
final class RemoveAssetSymlinkFolders
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

                if ($this->linkPointsTo($to, $from)) {
                    return [$to, function (string $path): void {
                        $deleted = windows_os() ? @rmdir($path) : $this->files->delete($path);
                        clearstatcache(false, $path);

                        if (! $deleted || is_symlink($path) || $this->files->exists($path)) {
                            throw new RuntimeException("Unable to remove asset symlink [{$path}].");
                        }
                    }];
                }

                return null;
            })->filter()
            ->each(static function ($payload) {
                /** @var array{0: string, 1: Closure(string): void} $payload */
                value($payload[1], $payload[0]);
            });
    }

    /**
     * Determine whether a symlink points to the asset source owned by this action.
     */
    private function linkPointsTo(string $link, string $target): bool
    {
        if (! is_symlink($link)) {
            return false;
        }

        $resolvedLink = realpath($link);
        $resolvedTarget = realpath($target);

        if ($resolvedLink !== false && $resolvedTarget !== false) {
            return $resolvedLink === $resolvedTarget;
        }

        return readlink($link) === $target;
    }
}
